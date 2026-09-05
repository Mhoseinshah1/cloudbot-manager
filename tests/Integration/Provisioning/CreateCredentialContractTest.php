<?php

declare(strict_types=1);

use App\Authorization\RoleProvisioner;
use App\Cloud\Capabilities\SupportsPasswordReset;
use App\Cloud\Contracts\CloudProviderInterface;
use App\Cloud\Data\ProviderCreateResult;
use App\Cloud\Data\ProviderServerData;
use App\Cloud\Data\SensitiveRootCredential;
use App\Cloud\Fake\Models\FakeProviderServer;
use App\Enums\OrderStatus;
use App\Models\AuditLog;
use App\Models\Invoice;
use App\Models\NotificationLog;
use App\Models\OutboxMessage;
use App\Models\ProvisioningAttempt;
use App\Models\Server;
use App\Models\Subscription;
use App\Provisioning\Data\ProvisioningResult;
use App\Provisioning\ProvisioningService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\Provisioning\ProvisioningFloor;
use Tests\Support\Provisioning\Simulator;

/**
 * A one-time root password reaches encrypted storage, or the order is parked.
 *
 * `ProviderServerData` still carries no credential, and must not: it is what
 * `getServer()`, `listServers()` and `findByProvisioningToken()` return, and
 * those are read by reconciliation, inventory, comparisons and logs constantly.
 * A credential field there would be a credential in all of them.
 *
 * So the credential travels in a create-specific result, lives in exactly one
 * frame, and ends at `servers.root_password_encrypted`. Nothing else stores it,
 * and no second secret store exists.
 *
 * The crash window is closed by rotation rather than by remembering. If the
 * local write fails, the create-time password is gone — deliberately — and
 * recovery asks the provider for a new one before anything is delivered. That
 * is safe here and nowhere else: the customer has not been given this server, so
 * invalidating the old password locks nobody out, duplicates no machine and
 * destroys no data.
 */
beforeEach(function (): void {
    app(RoleProvisioner::class)->sync();

    $this->floor = ProvisioningFloor::open();
});

/**
 * Whether the provider currently accepts this credential for its one machine.
 *
 * The only question the simulator will answer about a password. There is
 * deliberately no way to ask it what the password *is*: it keeps a one-way
 * verifier, exactly so nothing — including a test — can read a credential back
 * out of its storage.
 */
function providerAccepts(string|SensitiveRootCredential|null $credential): bool
{
    if ($credential === null) {
        return false;
    }

    $serverId = (string) FakeProviderServer::query()->value('provider_server_id');

    // Resolved plainly rather than through the container, so the answer does
    // not depend on whichever scripted wrapper a test happens to have installed.
    // The simulator's state lives in PostgreSQL, so a fresh instance sees it.
    return Simulator::plain()->credentialMatches($serverId, $credential);
}

/** The credential this order actually delivered, decrypted through the model cast. */
function deliveredCredential(): ?string
{
    return Server::query()->sole()->root_password_encrypted;
}

/** Break the local server write without touching anything remote. */
function blockServerWrites(): void
{
    DB::statement('ALTER TABLE servers ADD CONSTRAINT cbm010_block_insert CHECK (id < 0) NOT VALID');
    DB::statement('ALTER TABLE servers VALIDATE CONSTRAINT cbm010_block_insert');
}

function allowServerWrites(): void
{
    DB::statement('ALTER TABLE servers DROP CONSTRAINT IF EXISTS cbm010_block_insert');
}

it('keeps ordinary provider reads free of any credential', function (): void {
    // The contract, still. Only the create may carry a secret.
    $create = (new ReflectionMethod(CloudProviderInterface::class, 'createServer'))->getReturnType();
    $read = (new ReflectionMethod(CloudProviderInterface::class, 'getServer'))->getReturnType();

    expect((string) $create)->toBe(ProviderCreateResult::class)
        ->and((string) $read)->toBe('?'.ProviderServerData::class);

    $fields = array_map(
        static fn (ReflectionProperty $property): string => $property->getName(),
        (new ReflectionClass(ProviderServerData::class))->getProperties(),
    );

    expect($fields)->not->toContain('rootCredential')
        ->and($fields)->not->toContain('rootPassword');
});

it('never lets a credential escape its value object by accident', function (): void {
    $secret = 'rt-'.bin2hex(random_bytes(16));
    $credential = new SensitiveRootCredential($secret);

    // Exactly one deliberate way out, and every accidental one redacted.
    expect($credential->reveal())->toBe($secret)
        ->and(json_encode(['c' => $credential], JSON_THROW_ON_ERROR))->not->toContain($secret)
        ->and(print_r($credential, true))->not->toContain($secret)
        ->and(json_encode($credential, JSON_THROW_ON_ERROR))->not->toContain($secret);

    ob_start();
    var_dump($credential);
    expect((string) ob_get_clean())->not->toContain($secret);
});

it('stores a create-time root password encrypted and delivers the order', function (): void {
    $order = $this->floor->paidOrder();

    $result = app(ProvisioningService::class)->provision($order);

    $server = Server::query()->sole();
    $stored = deliveredCredential();

    expect($result->state)->toBe(ProvisioningResult::Provisioned)
        ->and($order->fresh()->status)->toBe(OrderStatus::Provisioned)
        ->and(Server::query()->count())->toBe(1)
        ->and(Subscription::query()->count())->toBe(1)
        ->and(Invoice::query()->count())->toBe(1)
        // Something was stored, and the provider accepts exactly that. Proven
        // by presenting it rather than by reading the provider's storage,
        // because the provider keeps no password to read.
        ->and($stored)->not->toBeNull()
        ->and(providerAccepts($stored))->toBeTrue()
        // And ciphertext at rest, not the password as typed.
        ->and(DB::table('servers')->where('id', $server->getKey())->value('root_password_encrypted'))
        ->not->toBe($stored)
        // No credential-recovery attempt: nothing was lost, so nothing rotated.
        ->and(ProvisioningAttempt::query()->where('stage', 'credential_recovery')->count())->toBe(0);
});

it('accepts a normalized create that carries no credential at all', function (): void {
    // A provider that authenticates by key issues no root password, and the
    // contract says so with a null rather than an empty string somebody has to
    // interpret. The column stays nullable and the order still delivers.
    Simulator::script()->withoutCredential();

    $order = $this->floor->paidOrder();
    $result = app(ProvisioningService::class)->provision($order);

    expect($result->state)->toBe(ProvisioningResult::Provisioned)
        ->and($order->fresh()->status)->toBe(OrderStatus::Provisioned)
        ->and(Server::query()->count())->toBe(1)
        ->and(Subscription::query()->count())->toBe(1)
        ->and(deliveredCredential())->toBeNull();

    // And the schema has not been tightened into requiring one.
    expect(Schema::getColumnListing('servers'))->toContain('root_password_encrypted')
        ->and(DB::selectOne(
            "SELECT is_nullable FROM information_schema.columns
             WHERE table_name = 'servers' AND column_name = 'root_password_encrypted'"
        )->is_nullable)->toBe('YES');
});

it('rotates a credential lost when the local write failed, and delivers once', function (): void {
    $order = $this->floor->paidOrder();
    $scripted = Simulator::script();

    blockServerWrites();
    $failed = app(ProvisioningService::class)->provision($order);
    allowServerWrites();

    // The machine exists and nothing local does. The create-time password was
    // never captured by this test either — it is gone, which is the point.
    expect($failed->state)->toBe(ProvisioningResult::Retryable)
        ->and(FakeProviderServer::query()->count())->toBe(1)
        ->and(Server::query()->count())->toBe(0)
        ->and(Subscription::query()->count())->toBe(0);

    $recovered = app(App\Provisioning\ReconciliationService::class)->reconcile($order->fresh());

    $server = Server::query()->sole();
    $stored = deliveredCredential();

    expect($recovered->state)->toBe(ProvisioningResult::Provisioned)
        // The delivered credential is the one the provider now accepts.
        ->and($stored)->not->toBeNull()
        ->and(providerAccepts($stored))->toBeTrue()
        ->and(DB::table('servers')->where('id', $server->getKey())->value('root_password_encrypted'))
        ->not->toBe($stored)
        // One of everything, same token, no second create, no refund.
        ->and(Server::query()->count())->toBe(1)
        ->and(Subscription::query()->count())->toBe(1)
        ->and(FakeProviderServer::query()->count())->toBe(1)
        ->and($order->fresh()->provisioning_uuid)->toBe(FakeProviderServer::query()->value('provisioning_token'))
        ->and($order->fresh()->status)->toBe(OrderStatus::Provisioned)
        ->and($scripted->callCount('createServer'))->toBe(1)
        ->and(App\Models\WalletTransaction::query()->where('type', 'refund')->count())->toBe(0)
        // Exactly one rotation, counted on its own stage.
        ->and(ProvisioningAttempt::query()->where('stage', 'credential_recovery')->count())->toBe(1);
});

it('does not need the create-time credential kept while a machine is still building', function (): void {
    $order = $this->floor->paidOrder();

    // The create answers with a machine that is not ready. Its credential is
    // dropped rather than parked anywhere durable.
    $scripted = Simulator::script();
    $scripted->afterCreate(static fn (ProviderServerData $server): ProviderServerData => new ProviderServerData(
        providerServerId: $server->providerServerId,
        provisioningToken: $server->provisioningToken,
        name: $server->name,
        providerPlanId: $server->providerPlanId,
        providerLocationId: $server->providerLocationId,
        providerImageId: $server->providerImageId,
        status: App\Cloud\Enums\ProviderServerStatus::Provisioning,
        powerState: $server->powerState,
        ipv4: $server->ipv4,
        ipv6: $server->ipv6,
        metadata: $server->metadata,
    ));

    $pending = app(ProvisioningService::class)->provision($order);

    expect($pending->state)->toBe(ProvisioningResult::RemotePending)
        ->and(Server::query()->count())->toBe(0);

    // The machine comes up. Recovery obtains a fresh credential rather than
    // needing the one that was dropped.
    $scripted->afterCreate(static fn (ProviderServerData $server): ProviderServerData => $server);
    FakeProviderServer::query()->update(['status' => App\Cloud\Enums\ProviderServerStatus::Active]);

    $delivered = app(App\Provisioning\ReconciliationService::class)->reconcile($order->fresh());
    $stored = deliveredCredential();

    expect($delivered->state)->toBe(ProvisioningResult::Provisioned)
        ->and($stored)->not->toBeNull()
        ->and(providerAccepts($stored))->toBeTrue()
        ->and(FakeProviderServer::query()->count())->toBe(1)
        ->and($scripted->callCount('createServer'))->toBe(1);
});

it('rotates again when a worker dies before the recovered credential is stored', function (): void {
    $order = $this->floor->paidOrder();
    $scripted = Simulator::script();

    blockServerWrites();
    app(ProvisioningService::class)->provision($order);

    // First recovery: the provider issues a password and the local write is
    // still broken, so it is lost exactly as a worker death would lose it.
    // Observed here only so the test can prove it later stops working; nothing
    // in the system ever holds it this way.
    $lost = null;
    $scripted->onPasswordReset(static function (string $serverId, $inner) use (&$lost) {
        $reset = $inner->resetRootPassword($serverId);
        $lost = $reset->rootCredential?->reveal();

        return $reset;
    });

    $firstRecovery = app(App\Provisioning\ReconciliationService::class)->reconcile($order->fresh());

    allowServerWrites();

    expect($firstRecovery->state)->toBe(ProvisioningResult::Retryable)
        ->and(Server::query()->count())->toBe(0)
        ->and($lost)->not->toBeNull()
        // It really was this machine's password a moment ago.
        ->and(providerAccepts($lost))->toBeTrue();

    foreach (['provisioning_attempts', 'outbox_messages', 'audit_logs'] as $table) {
        $rows = DB::table($table)->get()->map(
            static fn (object $row): string => (string) json_encode($row, JSON_THROW_ON_ERROR),
        )->implode(' ');

        expect($rows)->not->toContain($lost, $table);
    }

    // Second recovery rotates again. The newer password supersedes the one
    // nobody ever received, which is only safe because nobody received it.
    $second = app(App\Provisioning\ReconciliationService::class)->reconcile($order->fresh());
    $current = deliveredCredential();

    expect($second->state)->toBe(ProvisioningResult::Provisioned)
        ->and($current)->not->toBe($lost)
        ->and(providerAccepts($current))->toBeTrue()
        // And the superseded one no longer works, which a plaintext column
        // could only have pretended to show.
        ->and(providerAccepts($lost))->toBeFalse()
        ->and(Server::query()->count())->toBe(1)
        ->and(FakeProviderServer::query()->count())->toBe(1)
        ->and($scripted->callCount('createServer'))->toBe(1)
        ->and(App\Models\WalletTransaction::query()->where('type', 'refund')->count())->toBe(0);
});

it('parks an order when the provider cannot issue a replacement credential', function (): void {
    $order = $this->floor->paidOrder();

    // A create that works, through an adapter that offers only the core
    // contract — no password reset, so no safe way to obtain access.
    $limited = Simulator::coreOnly();

    blockServerWrites();
    app(ProvisioningService::class)->provision($order);
    allowServerWrites();

    expect($limited)->not->toBeInstanceOf(SupportsPasswordReset::class)
        ->and(FakeProviderServer::query()->count())->toBe(1)
        ->and(Server::query()->count())->toBe(0);

    $parked = app(App\Provisioning\ReconciliationService::class)->reconcile($order->fresh());

    $alerts = OutboxMessage::query()->where('topic', App\Outbox\OutboxTopic::ProvisioningNeedsAttention)->get();

    expect($parked->state)->toBe(ProvisioningResult::NeedsAttention)
        ->and($order->fresh()->status)->toBe(OrderStatus::NeedsAttention)
        // No delivery claiming success.
        ->and(Server::query()->count())->toBe(0)
        ->and(Subscription::query()->count())->toBe(0)
        ->and(OutboxMessage::query()->where('topic', App\Outbox\OutboxTopic::ProvisioningSucceeded)->count())->toBe(0)
        // The machine exists and is billable, so no refund and no second create.
        ->and(App\Models\WalletTransaction::query()->where('type', 'refund')->count())->toBe(0)
        ->and(FakeProviderServer::query()->count())->toBe(1)
        ->and($order->fresh()->provisioning_uuid)->toBe(FakeProviderServer::query()->value('provisioning_token'))
        // And an operator is told why, by a reason a person can act on.
        ->and($alerts)->not->toBeEmpty()
        ->and((string) json_encode($alerts->last()->payload, JSON_THROW_ON_ERROR))
        ->toContain(App\Provisioning\CredentialRecovery::Unsupported);
});

it('parks an order once the durable credential-recovery budget is spent', function (): void {
    $maximum = (int) config('cloudbot.provisioning.credential_recovery_max_attempts', 3);
    $order = $this->floor->paidOrder();

    $scripted = Simulator::script();

    blockServerWrites();
    app(ProvisioningService::class)->provision($order);
    $createAttempts = (int) $order->fresh()->attempts;

    // Every reset refused, deterministically. The scripted hook is one-shot, so
    // it is re-armed for each round.
    for ($round = 0; $round < $maximum + 2; $round++) {
        $scripted->rejectOperation('resetRootPassword', App\Cloud\Enums\ProviderErrorCategory::Unavailable);
        app(App\Provisioning\ReconciliationService::class)->reconcile($order->fresh());
    }

    allowServerWrites();

    $alerts = OutboxMessage::query()->where('topic', App\Outbox\OutboxTopic::ProvisioningNeedsAttention)->get();

    expect($scripted->callCount('resetRootPassword'))->toBe($maximum)
        ->and(ProvisioningAttempt::query()->where('stage', 'credential_recovery')->count())->toBe($maximum)
        // The create budget is untouched. A password rotation is not a machine.
        ->and((int) $order->fresh()->attempts)->toBe($createAttempts)
        ->and($order->fresh()->status)->toBe(OrderStatus::NeedsAttention)
        ->and(FakeProviderServer::query()->count())->toBe(1)
        ->and(Server::query()->count())->toBe(0)
        ->and(OutboxMessage::query()->where('topic', App\Outbox\OutboxTopic::ProvisioningSucceeded)->count())->toBe(0)
        ->and(App\Models\WalletTransaction::query()->where('type', 'refund')->count())->toBe(0)
        ->and($scripted->callCount('createServer'))->toBe(1)
        ->and($alerts)->not->toBeEmpty()
        ->and((string) json_encode($alerts->last()->payload, JSON_THROW_ON_ERROR))
        ->toContain(App\Provisioning\CredentialRecovery::Exhausted);
});

it('keeps every credential it handles out of every durable record', function (): void {
    $order = $this->floor->paidOrder();
    $scripted = Simulator::script();

    // Both credentials this run produces, observed only so the scan has
    // something to look for. Neither is read back out of any storage — the one
    // from the create is captured as it is returned, and the one from the reset
    // as the provider issues it.
    $createTime = null;
    $scripted->afterCreate(static function (ProviderServerData $server) use (&$createTime): ProviderServerData {
        return $server;
    });

    blockServerWrites();
    app(ProvisioningService::class)->provision($order);
    allowServerWrites();

    $rotated = null;
    $scripted->onPasswordReset(static function (string $serverId, $inner) use (&$rotated) {
        $reset = $inner->resetRootPassword($serverId);
        $rotated = $reset->rootCredential?->reveal();

        return $reset;
    });

    app(App\Provisioning\ReconciliationService::class)->reconcile($order->fresh());

    $server = Server::query()->sole();
    $delivered = deliveredCredential();

    expect($rotated)->not->toBeNull()
        ->and($delivered)->toBe($rotated)
        ->and(providerAccepts($delivered))->toBeTrue();

    // Every durable surface, read raw. `fake_provider_servers` is in this list
    // deliberately: it is a table in the application's own migration set, and a
    // plaintext credential there would be a second secret store whatever the
    // provider that owns it is for.
    $haystacks = [
        'fake_provider_servers' => (string) json_encode(DB::table('fake_provider_servers')->get()->all()),
        'fake_provider_actions' => (string) json_encode(DB::table('fake_provider_actions')->get()->all()),
        'servers_raw' => (string) json_encode(DB::table('servers')->get()->all()),
        'provider_metadata' => (string) json_encode(DB::table('servers')->pluck('provider_metadata')->all()),
        'provisioning_attempts' => (string) json_encode(ProvisioningAttempt::query()->get()->toArray()),
        'outbox' => (string) json_encode(OutboxMessage::query()->get()->toArray()),
        'audit' => (string) json_encode(AuditLog::query()->get()->toArray()),
        'notifications' => (string) json_encode(NotificationLog::query()->get()->toArray()),
        'telegram_updates' => (string) json_encode(DB::table('telegram_updates')->get()->all()),
        'server_json' => (string) json_encode($server->fresh()->toArray()),
        'server_dto' => (string) json_encode(
            Simulator::plain()->getServer($server->provider_server_id),
        ),
    ];

    foreach (array_filter([$rotated]) as $secret) {
        foreach ($haystacks as $name => $haystack) {
            expect(str_contains($haystack, $secret))->toBeFalse("{$name} carried a credential");
        }
    }

    // The provider keeps a digest and nothing more: it can confirm a credential
    // and can never produce one.
    $verifier = (string) DB::table('fake_provider_servers')->value('root_password_verifier');

    expect(DB::getSchemaBuilder()->getColumnListing('fake_provider_servers'))
        ->not->toContain('root_password')
        ->and($verifier)->toHaveLength(64)
        ->and($verifier)->not->toBe($rotated);

    // The one durable customer-access credential, and only as ciphertext at rest.
    expect($server->fresh()->root_password_encrypted)->toBe($delivered)
        ->and(DB::table('servers')->where('id', $server->getKey())->value('root_password_encrypted'))
        ->not->toBe($delivered);
});

it('refuses to serialize a credential rather than quietly redacting it', function (): void {
    $secret = 'rt-'.bin2hex(random_bytes(16));
    $credential = new SensitiveRootCredential($secret);

    // Fail closed. Serializing means somebody put a credential somewhere
    // durable — a queue payload, a session, a cache entry — and a placeholder
    // would hide that mistake instead of surfacing it.
    expect(fn () => serialize($credential))->toThrow(LogicException::class);

    try {
        serialize($credential);
    } catch (LogicException $exception) {
        expect($exception->getMessage())->not->toContain($secret);
    }
});

it('never puts a credential on a queue in the production paths that hold one', function (): void {
    // The two jobs that exist anywhere near a credential carry row ids, and a
    // job payload is serialized into Redis and printed whole in a failed-job
    // record. If either ever closed over the object, the fail-closed
    // serializer above would throw here rather than in production.
    $order = $this->floor->paidOrder();

    Illuminate\Support\Facades\Queue::fake();

    app(ProvisioningService::class)->provision($order);

    $server = Server::query()->sole();

    foreach ([
        new App\Jobs\ProvisionOrderJob((int) $order->getKey()),
        new App\Jobs\ExecuteServerActionJob(1),
    ] as $job) {
        $payload = serialize($job);

        expect($payload)->not->toContain((string) $server->root_password_encrypted);
    }
});
