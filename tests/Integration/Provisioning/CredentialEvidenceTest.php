<?php

declare(strict_types=1);

use App\Authorization\RoleProvisioner;
use App\Cloud\Data\ProviderCreateResult;
use App\Cloud\Data\ProviderServerData;
use App\Cloud\Enums\ProviderCreateDisposition;
use App\Cloud\Enums\ProviderServerStatus;
use App\Cloud\Fake\Models\FakeProviderServer;
use App\Enums\CredentialEvidence;
use App\Enums\OrderStatus;
use App\Models\ProvisioningAttempt;
use App\Models\Server;
use App\Models\Subscription;
use App\Models\WalletTransaction;
use App\Provisioning\AttemptRecorder;
use App\Provisioning\Data\ProvisioningResult;
use App\Provisioning\ProvisioningService;
use App\Provisioning\ReconciliationService;
use Illuminate\Support\Facades\DB;
use Tests\Support\Provisioning\ProvisioningFloor;
use Tests\Support\Provisioning\Simulator;

/**
 * RCH-001. A null credential was answering two different questions.
 *
 * A create-specific result can carry no credential for two unrelated reasons: a
 * provider that authenticates by key issues none, and a token that already had
 * a server has none left to give. Read as the same fact, the second becomes a
 * claim nobody made — that this machine has no root password — and recovery
 * would hand a customer a machine they cannot log into and mark it delivered.
 *
 * The mirror failure was just as real. Every active remote server with no local
 * row was pushed through credential rotation, so a provider that legitimately
 * issues no password and implements no reset had its perfectly deliverable
 * orders parked for a person.
 *
 * The answer is evidence rather than inference: the create response says what
 * it did, that fact is committed before anything can lose it, and recovery
 * reads it. What the remote DTO says is deliberately not consulted — it carries
 * no credential by construction, so its silence means nothing.
 */
beforeEach(function (): void {
    app(RoleProvisioner::class)->sync();

    $this->floor = ProvisioningFloor::open();
});

function blockLocalServerWrites(): void
{
    DB::statement('ALTER TABLE servers ADD CONSTRAINT rch001_block CHECK (id < 0) NOT VALID');
    DB::statement('ALTER TABLE servers VALIDATE CONSTRAINT rch001_block');
}

function allowLocalServerWrites(): void
{
    DB::statement('ALTER TABLE servers DROP CONSTRAINT IF EXISTS rch001_block');
}

/** A provider that builds servers and issues no root password, and cannot reset one. */
function credentiallessProvider(): Tests\Support\Servers\CoreOnlyProvider
{
    $limited = Simulator::coreOnly();
    $limited->withoutCredential();

    return $limited;
}

afterEach(function (): void {
    allowLocalServerWrites();
});

it('A. provisions a credentialless create normally', function (): void {
    credentiallessProvider();

    $order = $this->floor->paidOrder();
    $result = app(ProvisioningService::class)->provision($order);

    expect($result->state)->toBe(ProvisioningResult::Provisioned)
        ->and(Server::query()->sole()->root_password_encrypted)->toBeNull()
        ->and(app(AttemptRecorder::class)->credentialEvidence($order->fresh()))
        ->toBe(CredentialEvidence::KnownNone);
});

it('B. delivers a credentialless server after the local write failed, without any reset', function (): void {
    $limited = credentiallessProvider();

    $order = $this->floor->paidOrder();

    blockLocalServerWrites();
    $failed = app(ProvisioningService::class)->provision($order);
    allowLocalServerWrites();

    // The machine exists, nothing local does, and the durable evidence says
    // this create issued no password.
    expect($failed->state)->toBe(ProvisioningResult::Retryable)
        ->and(FakeProviderServer::query()->count())->toBe(1)
        ->and(Server::query()->count())->toBe(0)
        ->and(app(AttemptRecorder::class)->credentialEvidence($order->fresh()))
        ->toBe(CredentialEvidence::KnownNone);

    $recovered = app(ReconciliationService::class)->reconcile($order->fresh());

    expect($recovered->state)->toBe(ProvisioningResult::Provisioned)
        ->and($order->fresh()->status)->toBe(OrderStatus::Provisioned)
        ->and(Server::query()->count())->toBe(1)
        ->and(Subscription::query()->count())->toBe(1)
        ->and(Server::query()->sole()->root_password_encrypted)->toBeNull()
        // Not one reset. This provider does not even implement the capability,
        // and it never needed to.
        ->and($limited->callCount('resetRootPassword'))->toBe(0)
        ->and(ProvisioningAttempt::query()->where('stage', 'credential_recovery')->count())->toBe(0)
        // One VPS, same token, no refund.
        ->and(FakeProviderServer::query()->count())->toBe(1)
        ->and($order->fresh()->provisioning_uuid)->toBe(FakeProviderServer::query()->value('provisioning_token'))
        ->and(WalletTransaction::query()->where('type', 'refund')->count())->toBe(0);
});

it('C. delivers a credentialless machine that was still building at create time', function (): void {
    $limited = Simulator::coreOnly();
    $limited->afterCreate(static fn (ProviderServerData $server): ProviderCreateResult => ProviderCreateResult::created(
        new ProviderServerData(
            providerServerId: $server->providerServerId,
            provisioningToken: $server->provisioningToken,
            name: $server->name,
            providerPlanId: $server->providerPlanId,
            providerLocationId: $server->providerLocationId,
            providerImageId: $server->providerImageId,
            status: ProviderServerStatus::Provisioning,
            powerState: $server->powerState,
            ipv4: $server->ipv4,
            ipv6: $server->ipv6,
            metadata: $server->metadata,
        ),
    ));

    $order = $this->floor->paidOrder();
    $pending = app(ProvisioningService::class)->provision($order);

    // The fact is recorded even though nothing was persisted, because the
    // response is the only thing that will ever say it.
    expect($pending->state)->toBe(ProvisioningResult::RemotePending)
        ->and(Server::query()->count())->toBe(0)
        ->and(app(AttemptRecorder::class)->credentialEvidence($order->fresh()))
        ->toBe(CredentialEvidence::KnownNone);

    FakeProviderServer::query()->update(['status' => ProviderServerStatus::Active]);

    $delivered = app(ReconciliationService::class)->reconcile($order->fresh());

    expect($delivered->state)->toBe(ProvisioningResult::Provisioned)
        ->and(Server::query()->sole()->root_password_encrypted)->toBeNull()
        ->and($limited->callCount('resetRootPassword'))->toBe(0);
});

it('D. still rotates when the create is known to have issued a credential', function (): void {
    $scripted = Simulator::script();
    $order = $this->floor->paidOrder();

    blockLocalServerWrites();
    app(ProvisioningService::class)->provision($order);
    allowLocalServerWrites();

    expect(app(AttemptRecorder::class)->credentialEvidence($order->fresh()))
        ->toBe(CredentialEvidence::KnownIssued);

    $recovered = app(ReconciliationService::class)->reconcile($order->fresh());
    $stored = Server::query()->sole()->root_password_encrypted;

    expect($recovered->state)->toBe(ProvisioningResult::Provisioned)
        ->and($stored)->not->toBeNull()
        ->and(Simulator::plain()->credentialMatches(
            (string) Server::query()->sole()->provider_server_id, $stored,
        ))->toBeTrue()
        ->and($scripted->callCount('resetRootPassword'))->toBe(1)
        ->and(ProvisioningAttempt::query()->where('stage', 'credential_recovery')->count())->toBe(1);
});

it('E. records no credential evidence for an existing-token replay', function (): void {
    // A replay establishes nothing. Writing "no credential issued" here would
    // be inventing a fact about a machine somebody else's create built.
    $provider = Simulator::plain();
    $token = (string) Illuminate\Support\Str::uuid();

    $first = $provider->createServer(new App\Cloud\Data\CreateServerRequest(
        $token,
        App\Cloud\Fake\FakeCatalog::PLAN_SMALL,
        App\Cloud\Fake\FakeCatalog::LOCATION_PRIMARY,
        App\Cloud\Fake\FakeCatalog::IMAGE_UBUNTU,
        'replay-probe',
    ));

    $second = $provider->createServer(new App\Cloud\Data\CreateServerRequest(
        $token,
        App\Cloud\Fake\FakeCatalog::PLAN_SMALL,
        App\Cloud\Fake\FakeCatalog::LOCATION_PRIMARY,
        App\Cloud\Fake\FakeCatalog::IMAGE_UBUNTU,
        'replay-probe',
    ));

    expect($first->disposition)->toBe(ProviderCreateDisposition::Created)
        ->and($first->hasCredential())->toBeTrue()
        ->and($first->provesNoCredential())->toBeFalse()
        ->and($second->disposition)->toBe(ProviderCreateDisposition::Existing)
        ->and($second->rootCredential)->toBeNull()
        // The load-bearing assertion: a replay carrying no credential does not
        // prove the machine has none.
        ->and($second->provesNoCredential())->toBeFalse();

    // And the recorder writes nothing for it.
    $order = $this->floor->paidOrder();
    $plan = app(App\Provisioning\OrderPlanner::class)->plan(
        app(ProvisioningService::class)->prepare($order),
    );
    $attempt = app(AttemptRecorder::class)->open($order->fresh(), App\Enums\ProvisioningStage::Create, $plan);

    app(AttemptRecorder::class)->recordCreateResponse($attempt, $second);

    expect(app(AttemptRecorder::class)->credentialEvidence($order->fresh()))
        ->toBe(CredentialEvidence::Unknown)
        ->and($attempt->fresh()->result_summary ?? [])->not->toHaveKey('root_credential_issued');
});

it('F. keeps issuance evidence when a later create replays the existing server', function (): void {
    $scripted = Simulator::script();
    $order = $this->floor->paidOrder();

    blockLocalServerWrites();
    app(ProvisioningService::class)->provision($order);

    expect(app(AttemptRecorder::class)->credentialEvidence($order->fresh()))
        ->toBe(CredentialEvidence::KnownIssued);

    // A second provisioning run replays the token: the provider returns the
    // machine it already has, with no credential. That must not overwrite what
    // the original create established.
    app(ProvisioningService::class)->provision($order->fresh());
    allowLocalServerWrites();

    expect(app(AttemptRecorder::class)->credentialEvidence($order->fresh()))
        ->toBe(CredentialEvidence::KnownIssued);

    $recovered = app(ReconciliationService::class)->reconcile($order->fresh());

    expect($recovered->state)->toBe(ProvisioningResult::Provisioned)
        ->and(Server::query()->sole()->root_password_encrypted)->not->toBeNull()
        ->and($scripted->callCount('resetRootPassword'))->toBeGreaterThanOrEqual(1)
        ->and(FakeProviderServer::query()->count())->toBe(1);
});

it('G. never delivers credentialless when the create response was never recorded', function (): void {
    // The worker died between the provider acting and the fact being written.
    // Nothing durable says what kind of machine this is, and guessing
    // "credentialless" would hand over a server nobody can log into.
    $limited = credentiallessProvider();

    $order = $this->floor->paidOrder();

    blockLocalServerWrites();
    app(ProvisioningService::class)->provision($order);
    allowLocalServerWrites();

    // Erase the evidence, exactly as a death before the write would.
    ProvisioningAttempt::query()->update(['result_summary' => null]);

    expect(app(AttemptRecorder::class)->credentialEvidence($order->fresh()))
        ->toBe(CredentialEvidence::Unknown);

    $parked = app(ReconciliationService::class)->reconcile($order->fresh());

    expect($parked->state)->toBe(ProvisioningResult::NeedsAttention)
        ->and($order->fresh()->status)->toBe(OrderStatus::NeedsAttention)
        // No delivery claiming success, and no invented credentialless answer.
        ->and(Server::query()->count())->toBe(0)
        ->and(Subscription::query()->count())->toBe(0)
        ->and($limited->callCount('resetRootPassword'))->toBe(0)
        // The machine exists and is billable, so no refund and no second create.
        ->and(FakeProviderServer::query()->count())->toBe(1)
        ->and(WalletTransaction::query()->where('type', 'refund')->count())->toBe(0);
});
