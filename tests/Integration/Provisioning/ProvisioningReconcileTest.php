<?php

declare(strict_types=1);

use App\Authorization\RoleProvisioner;
use App\Cloud\Data\ProviderServerData;
use App\Cloud\Data\SafeMetadata;
use App\Cloud\Enums\ProviderPowerState;
use App\Cloud\Enums\ProviderServerStatus;
use App\Cloud\Fake\Models\FakeProviderServer;
use App\Enums\OrderFailureCategory;
use App\Enums\OrderStatus;
use App\Enums\ProvisioningOutcome;
use App\Enums\SettingKey;
use App\Enums\SettingType;
use App\Models\OutboxMessage;
use App\Models\Server;
use App\Models\Setting;
use App\Models\Subscription;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Outbox\OutboxTopic;
use App\Provisioning\Data\ProvisioningResult;
use App\Provisioning\ProvisioningService;
use App\Provisioning\ReconciliationService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Tests\Support\Provisioning\ProvisioningFloor;
use Tests\Support\Provisioning\Simulator;

/**
 * The sweeper that resolves orders nobody finished.
 *
 * It works from the durable provisioning token and from nothing else, and the
 * three answers it can get are not interchangeable: one machine, none, or more
 * than one. The third is the interesting case — a provider whose state has
 * drifted can offer two servers carrying one token, and picking either hands a
 * customer a machine while the other bills quietly forever.
 */
beforeEach(function (): void {
    app(RoleProvisioner::class)->sync();

    $this->floor = ProvisioningFloor::open();
    $this->provisioning = app(ProvisioningService::class);
    $this->reconciliation = app(ReconciliationService::class);
});

it('finishes an order whose machine already exists', function (): void {
    $order = $this->floor->paidOrder();

    $scripted = Simulator::script();
    $scripted->loseCreateResponse();
    $this->provisioning->provision($order);

    $scripted->afterCreate(fn ($server) => $server);

    $result = $this->reconciliation->reconcile($order->fresh());

    expect($result->state)->toBe(ProvisioningResult::Provisioned)
        ->and($scripted->callCount('createServer'))->toBe(1)
        ->and(Server::query()->count())->toBe(1)
        ->and(Subscription::query()->count())->toBe(1);
});

it('never chooses between two machines claiming one token', function (): void {
    $order = $this->floor->paidOrder();
    $charged = (int) User::query()->whereKey($this->floor->customer->id)->value('wallet_balance_toman');

    // Claimed and genuinely built, then left unfinished — a worker that died
    // between the provider answering and the local write.
    $prepared = $this->provisioning->prepare($order);
    $token = (string) $prepared->provisioning_uuid;

    Simulator::plain()->createServer(new App\Cloud\Data\CreateServerRequest(
        provisioningToken: $token,
        providerPlanId: App\Cloud\Fake\FakeCatalog::PLAN_SMALL,
        providerLocationId: App\Cloud\Fake\FakeCatalog::LOCATION_PRIMARY,
        providerImageId: App\Cloud\Fake\FakeCatalog::IMAGE_UBUNTU,
        name: 'cbm-first-machine',
    ));

    // The simulator's unique index makes a duplicate token impossible through
    // its own API, which is correct — so the drift is injected at the listing,
    // which is exactly how a corrupted provider would present it.
    $scripted = Simulator::script();
    $scripted->onListServers(function (array $servers) use ($token): array {
        $extra = new ProviderServerData(
            providerServerId: 'drifted-second-machine',
            provisioningToken: $token,
            name: 'cbm-duplicate',
            providerPlanId: App\Cloud\Fake\FakeCatalog::PLAN_SMALL,
            providerLocationId: App\Cloud\Fake\FakeCatalog::LOCATION_PRIMARY,
            providerImageId: App\Cloud\Fake\FakeCatalog::IMAGE_UBUNTU,
            status: ProviderServerStatus::Active,
            powerState: ProviderPowerState::On,
            ipv4: '198.51.100.9',
            ipv6: null,
            metadata: SafeMetadata::empty(),
        );

        return [...$servers, $extra];
    });

    $result = $this->reconciliation->reconcile($order->fresh());

    expect($result->state)->toBe(ProvisioningResult::NeedsAttention)
        ->and($result->outcome)->toBe(ProvisioningOutcome::AmbiguousRemoteMatch)
        // Nothing is chosen, nothing is delivered.
        ->and(Server::query()->count())->toBe(0)
        ->and(Subscription::query()->count())->toBe(0)
        ->and($order->fresh()->status)->toBe(OrderStatus::NeedsAttention)
        // Ambiguity is not a confirmed absence, so no refund.
        ->and((int) User::query()->whereKey($this->floor->customer->id)->value('wallet_balance_toman'))
        ->toBe($charged)
        ->and(WalletTransaction::query()->where('idempotency_key', $order->refundIdempotencyKey())->count())
        ->toBe(0)
        // And nothing is deleted to tidy the ambiguity away.
        ->and($scripted->callCount('deleteServer'))->toBe(0);

    $alert = OutboxMessage::query()
        ->where('topic', OutboxTopic::ProvisioningNeedsAttention)
        ->get()
        ->firstWhere(fn (OutboxMessage $m): bool => $m->payload['reason'] === 'ambiguous_remote_match');

    expect($alert)->not->toBeNull()
        ->and($alert->payload['match_count'])->toBe(2)
        ->and($alert->payload['provider_server_ids'])->toContain('drifted-second-machine');
});

it('never asks for a replacement when the token has been spent', function (): void {
    // A deleted server leaves a tombstone bound to its token. Calling create
    // again with that token returns the tombstone, not a new machine — so
    // asking would be a guaranteed waste and a fresh token would build
    // something nobody bought.
    $order = $this->floor->paidOrder();
    $charged = (int) User::query()->whereKey($this->floor->customer->id)->value('wallet_balance_toman');

    $scripted = Simulator::script();
    $scripted->loseCreateResponse();
    $this->provisioning->provision($order);

    $token = $order->fresh()->provisioning_uuid;
    $remote = FakeProviderServer::query()->firstOrFail();

    Simulator::plain()->deleteServer($remote->provider_server_id);
    $scripted->afterCreate(fn ($server) => $server);
    $scripted->calls = [];

    $result = $this->reconciliation->reconcile($order->fresh());

    expect($result->state)->toBe(ProvisioningResult::Refunded)
        // No replacement was requested.
        ->and($scripted->callCount('createServer'))->toBe(0)
        // The token is unchanged, and still bound to that one machine.
        ->and($order->fresh()->provisioning_uuid)->toBe($token)
        ->and(FakeProviderServer::query()->where('provisioning_token', $token)->count())->toBe(1)
        ->and($order->fresh()->status)->toBe(OrderStatus::Refunded)
        ->and($order->fresh()->failure_category)
        ->toBe(OrderFailureCategory::ReconciliationConfirmedNoServer)
        ->and((int) User::query()->whereKey($this->floor->customer->id)->value('wallet_balance_toman'))
        ->toBe($charged + $order->total_toman);
});

it('refuses to call absence what it simply could not read', function (): void {
    $order = $this->floor->paidOrder();
    $charged = (int) User::query()->whereKey($this->floor->customer->id)->value('wallet_balance_toman');

    $scripted = Simulator::script();
    $scripted->loseCreateResponse();
    $this->provisioning->provision($order);

    // The provider becomes unreadable. This is the single most dangerous
    // inference available: "I cannot see it" is not "it is not there".
    $scripted->onListServers(function (): never {
        throw App\Cloud\Exceptions\ProviderException::unavailable(
            App\Cloud\Fake\FakeProvider::CODE, 'The API is down.',
        );
    });

    $result = $this->reconciliation->reconcile($order->fresh());

    expect($result->state)->toBe(ProvisioningResult::Retryable)
        ->and($order->fresh()->status)->not->toBe(OrderStatus::Refunded)
        ->and((int) User::query()->whereKey($this->floor->customer->id)->value('wallet_balance_toman'))
        ->toBe($charged);
});

it('refunds only once the policy is exhausted and the provider is read clean', function (): void {
    $order = $this->floor->paidOrder();
    $charged = (int) User::query()->whereKey($this->floor->customer->id)->value('wallet_balance_toman');

    $scripted = Simulator::script();
    $scripted->rejectCreate(App\Cloud\Enums\ProviderErrorCategory::TransientProviderError, 'Broken.');

    $max = (int) config('cloudbot.provisioning.max_attempts');

    for ($run = 0; $run < $max; $run++) {
        $this->provisioning->provision($order->fresh());
    }

    // Attempts are spent and a successful read shows the provider holds
    // nothing for this token. That is a confirmed absence.
    $result = $this->reconciliation->reconcile($order->fresh());

    expect($result->state)->toBe(ProvisioningResult::Refunded)
        ->and($order->fresh()->status)->toBe(OrderStatus::Refunded)
        ->and($order->fresh()->failure_category)
        ->toBe(OrderFailureCategory::ReconciliationConfirmedNoServer)
        ->and((int) User::query()->whereKey($this->floor->customer->id)->value('wallet_balance_toman'))
        ->toBe($charged + $order->total_toman)
        ->and(WalletTransaction::query()->where('idempotency_key', $order->refundIdempotencyKey())->count())
        ->toBe(1);
});

it('reads a disabled provider for reconciliation but will not create with it', function (): void {
    $order = $this->floor->paidOrder();

    $scripted = Simulator::script();
    $scripted->loseCreateResponse();
    $this->provisioning->provision($order);

    // An operator disables the provider mid-incident.
    $this->floor->provider->forceFill(['enabled' => false])->save();
    $scripted->afterCreate(fn ($server) => $server);
    $scripted->calls = [];

    // Creating is refused: disabling stops new spending.
    $blocked = $this->provisioning->provision($order->fresh());

    expect($blocked->state)->toBe(ProvisioningResult::Retryable)
        ->and($scripted->callCount('createServer'))->toBe(0);

    // Reading is not: a paid customer whose machine may exist still needs
    // somebody to look, and looking spends nothing.
    $result = $this->reconciliation->reconcile($order->fresh());

    expect($result->state)->toBe(ProvisioningResult::Provisioned)
        ->and(Server::query()->count())->toBe(1)
        ->and($scripted->callCount('createServer'))->toBe(0);
});

it('selects stuck orders only when the threshold is readable', function (): void {
    $order = $this->floor->paidOrder();
    $this->provisioning->prepare($order);

    DB::table('orders')->where('id', $order->getKey())
        ->update(['updated_at' => CarbonImmutable::now()->subHour()]);

    expect($this->reconciliation->stuckOrders()->pluck('id')->all())->toBe([$order->id]);

    foreach (['abc', '', 'ten'] as $nonsense) {
        Setting::query()->updateOrCreate(
            ['key' => SettingKey::ProvisioningStuckAfterMinutes->value],
            ['value' => $nonsense, 'type' => SettingType::Integer],
        );

        // Fails closed rather than inventing a number that silently decides how
        // long a customer waits before anyone notices.
        expect($this->reconciliation->stuckOrders())->toBeNull();
    }

    Setting::query()->where('key', SettingKey::ProvisioningStuckAfterMinutes->value)->delete();

    expect($this->reconciliation->stuckOrders())->toBeNull();
});

it('leaves a recently updated order alone', function (): void {
    $order = $this->floor->paidOrder();
    $this->provisioning->prepare($order);

    // Inside the threshold: it may simply still be running.
    expect($this->reconciliation->stuckOrders()->pluck('id')->all())->toBe([]);
});

it('claims stuck orders in bounded batches, oldest first', function (): void {
    // Four purchases need a wallet that can pay for four.
    app(App\Wallet\WalletService::class)->credit(
        $this->floor->customer, 20_000_000, 'batch-top-up', 'Wallet top-up',
    );
    $orders = [];

    foreach (range(1, 4) as $index) {
        $order = $this->floor->paidOrder();
        $this->provisioning->prepare($order);
        DB::table('orders')->where('id', $order->getKey())
            ->update(['updated_at' => CarbonImmutable::now()->subMinutes(60 + $index)]);
        $orders[$index] = $order->id;
    }

    $claimed = $this->reconciliation->stuckOrders(2);

    expect($claimed)->toHaveCount(2)
        // Oldest first, so a backlog drains rather than starving.
        ->and($claimed->pluck('id')->all())->toBe([$orders[4], $orders[3]]);
});

it('reconciles a named order by hand even with no readable threshold', function (): void {
    $order = $this->floor->paidOrder();

    $scripted = Simulator::script();
    $scripted->loseCreateResponse();
    $this->provisioning->provision($order);
    $scripted->afterCreate(fn ($server) => $server);

    Setting::query()->where('key', SettingKey::ProvisioningStuckAfterMinutes->value)->delete();

    // Automatic selection is closed; the operator's escape hatch is not.
    $this->artisan('provisioning:reconcile')->assertExitCode(1);
    $this->artisan('provisioning:reconcile', ['--order' => $order->id])->assertExitCode(0);

    expect($order->fresh()->status)->toBe(OrderStatus::Provisioned)
        ->and(Server::query()->count())->toBe(1);
});

it('refuses to reconcile an order that never reached a provider', function (): void {
    $order = $this->floor->paidOrder();

    $result = $this->reconciliation->reconcile($order);

    expect($result->state)->toBe(ProvisioningResult::NotEligible)
        ->and($order->fresh()->status)->toBe(OrderStatus::Paid);
});
