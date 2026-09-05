<?php

declare(strict_types=1);

use App\Authorization\RoleProvisioner;
use App\Cloud\Fake\Models\FakeProviderServer;
use App\Enums\OrderStatus;
use App\Enums\SettingKey;
use App\Enums\SettingType;
use App\Jobs\ProvisionOrderJob;
use App\Models\Order;
use App\Models\OutboxMessage;
use App\Models\Server;
use App\Models\Setting;
use App\Models\Subscription;
use App\Models\WalletTransaction;
use App\Outbox\OutboxDispatcher;
use App\Provisioning\Data\ProvisioningResult;
use App\Provisioning\PaidOrderRecovery;
use App\Provisioning\ProvisioningService;
use App\Settings\SettingsService;
use Illuminate\Support\Facades\DB;
use Tests\Support\Provisioning\ProvisioningFloor;
use Tests\Support\Provisioning\Simulator;

/**
 * The paid order whose provisioning work was lost before it ever claimed a token.
 *
 * This is the one gap in the provisioning architecture that no sweep could see.
 * The order is paid, the provisioning intent was written inside the paying
 * transaction, the outbox delivered it, and the job it dispatched ran while an
 * operator had provisioning switched off. `paused` is deliberately not
 * retryable — arguing with a switch somebody turned off is not a retry policy —
 * so the job simply returns, and the outbox row that dispatched it is already
 * marked processed.
 *
 * What is left is an order at `paid` with no provisioning token, no server, no
 * unprocessed intent and no live job. The stuck-order sweep looks for
 * provisioning that started and stalled; this never started. Switching
 * provisioning back on schedules nothing, and a customer who has been charged
 * waits for somebody to notice by hand.
 */
beforeEach(function (): void {
    app(RoleProvisioner::class)->sync();

    $this->floor = ProvisioningFloor::open();
    $this->scripted = Simulator::script();
    $this->recovery = app(PaidOrderRecovery::class);

    // Selection is by age, and these orders are seconds old. Zero means "as
    // soon as it is due", which is what a test wants and what an operator who
    // wanted immediate recovery would set.
    app(SettingsService::class)->set(SettingKey::ProvisioningStuckAfterMinutes, 0, $this->floor->owner);

    // Dispatch runs the job in-process, so "the scheduler queued it and a
    // worker ran it" is one step here. The dispatch itself is the production
    // path; only the transport is collapsed.
    config(['queue.default' => 'sync']);
});

/**
 * Everything up to and including the lost delivery.
 *
 * Pay, let the outbox deliver its provisioning intent, and have the one job it
 * dispatches run while provisioning is switched off.
 */
function loseProvisioningWorkFor(ProvisioningFloor $floor): Order
{
    $order = $floor->paidOrder();

    $floor->setProvisioning(false);

    // The real outbox delivery: it dispatches ProvisionOrderJob and marks the
    // intent processed. With the sync transport the job runs inside it, which
    // is exactly what a notifications worker and a provisioning worker would
    // have done between them.
    app(OutboxDispatcher::class)->sweep();

    return $order->fresh();
}

it('leaves a paid order with no token and no unprocessed intent when the switch was off', function (): void {
    $order = loseProvisioningWorkFor($this->floor);

    $intent = OutboxMessage::query()
        ->where('deduplication_key', 'provisioning:order:'.$order->getKey().':requested')
        ->firstOrFail();

    // The exact state the finding describes, established rather than assumed.
    expect($order->status)->toBe(OrderStatus::Paid)
        ->and($order->provisioning_uuid)->toBeNull()
        ->and($intent->processed_at)->not->toBeNull()
        ->and($this->scripted->calls)->toBe([])
        ->and(Server::query()->count())->toBe(0)
        // And invisible to the sweep that exists: it selects provisioning and
        // needs_attention rows that carry a token.
        ->and(app(App\Provisioning\ReconciliationService::class)->stuckOrders()->pluck('id')->all())
        ->toBe([]);
});

it('provisions a lost paid order from scheduled recovery alone once the switch returns', function (): void {
    $order = loseProvisioningWorkFor($this->floor);
    $charged = (int) $this->floor->customer->fresh()->wallet_balance_toman;

    $this->floor->setProvisioning(true);

    // Only the scheduled command. Nothing calls ProvisioningService by hand,
    // which is the whole point: the recovery has to come from a path the
    // scheduler actually runs.
    $this->artisan('provisioning:reconcile')->assertExitCode(0);

    $fresh = $order->fresh();

    expect($fresh->status)->toBe(OrderStatus::Provisioned)
        ->and($fresh->provisioning_uuid)->not->toBeNull()
        ->and($this->scripted->callCount('createServer'))->toBe(1)
        ->and(FakeProviderServer::query()->count())->toBe(1)
        ->and(Server::query()->count())->toBe(1)
        ->and(Subscription::query()->count())->toBe(1)
        ->and(FakeProviderServer::query()->firstOrFail()->provisioning_token)
        ->toBe($fresh->provisioning_uuid)
        // The customer paid once and was not charged or refunded by the repair.
        ->and((int) $this->floor->customer->fresh()->wallet_balance_toman)->toBe($charged)
        ->and(WalletTransaction::query()->where('type', 'refund')->count())->toBe(0);
});

it('still yields one server when the recovery sweep runs repeatedly', function (): void {
    $order = loseProvisioningWorkFor($this->floor);

    $this->floor->setProvisioning(true);

    // Duplicate sweeps are expected: two schedulers, an operator running it by
    // hand, a sweep that overlapped its predecessor. One durable token is what
    // makes one machine, not one job.
    $this->artisan('provisioning:reconcile')->assertExitCode(0);
    $this->artisan('provisioning:reconcile')->assertExitCode(0);
    $this->artisan('provisioning:reconcile')->assertExitCode(0);

    expect(FakeProviderServer::query()->count())->toBe(1)
        ->and(Server::query()->count())->toBe(1)
        ->and(Subscription::query()->count())->toBe(1)
        ->and($order->fresh()->status)->toBe(OrderStatus::Provisioned)
        ->and($this->scripted->callCount('createServer'))->toBe(1);
});

it('queues nothing at all while provisioning is still switched off', function (): void {
    $order = loseProvisioningWorkFor($this->floor);

    // The switch is still off. Recovery must find the order and do nothing
    // with it — an operator who paused provisioning did not ask for a sweep
    // that provisions anyway.
    expect($this->recovery->lostOrders()->pluck('id')->all())->toBe([$order->id])
        ->and($this->recovery->recover())->toBe(0);

    $this->artisan('provisioning:reconcile')->assertExitCode(0);

    expect($this->scripted->calls)->toBe([])
        ->and(Server::query()->count())->toBe(0)
        ->and($order->fresh()->status)->toBe(OrderStatus::Paid);
});

it('selects lost paid orders only when the threshold is readable', function (): void {
    loseProvisioningWorkFor($this->floor);

    foreach (['abc', '', 'ten'] as $nonsense) {
        Setting::query()->updateOrCreate(
            ['key' => SettingKey::ProvisioningStuckAfterMinutes->value],
            ['value' => $nonsense, 'type' => SettingType::Integer],
        );

        // Fails closed, exactly as the stuck-order selector does. Inventing a
        // number here would silently decide how long a charged customer waits.
        expect($this->recovery->lostOrders())->toBeNull();
    }

    Setting::query()->where('key', SettingKey::ProvisioningStuckAfterMinutes->value)->delete();

    expect($this->recovery->lostOrders())->toBeNull();

    $this->floor->setProvisioning(true);
    $this->artisan('provisioning:reconcile')->assertExitCode(1);
});

it('never selects an order that already has a token or a server', function (): void {
    $withToken = $this->floor->paidOrder();
    app(ProvisioningService::class)->prepare($withToken);

    $delivered = $this->floor->paidOrder();
    expect(app(ProvisioningService::class)->provision($delivered)->state)
        ->toBe(ProvisioningResult::Provisioned);

    // Both are somebody else's business: one belongs to the token sweep, the
    // other is finished. Redispatching either would be work for nothing.
    expect($this->recovery->lostOrders()->pluck('id')->all())->toBe([]);
});

it('bounds the recovery batch and takes the oldest first', function (): void {
    app(App\Wallet\WalletService::class)->credit(
        $this->floor->customer, 20_000_000, 'recovery-top-up', 'Wallet top-up',
    );

    $this->floor->setProvisioning(false);
    $ids = [];

    foreach (range(1, 4) as $index) {
        $order = $this->floor->paidOrder();
        DB::table('orders')->where('id', $order->getKey())
            ->update(['updated_at' => Carbon\CarbonImmutable::now()->subMinutes(60 + $index)]);
        $ids[$index] = (int) $order->getKey();
    }

    $claimed = $this->recovery->lostOrders(2);

    expect($claimed)->toHaveCount(2)
        ->and($claimed->pluck('id')->all())->toBe([$ids[4], $ids[3]]);
});

it('puts recovered work on the provisioning queue and nowhere else', function (): void {
    expect(ProvisionOrderJob::queueName())->toBe(App\Support\Queues::Provisioning->value);
});
