<?php

declare(strict_types=1);

use App\Authorization\RoleProvisioner;
use App\Enums\ServerActionType;
use App\Enums\SubscriptionStatus;
use App\Models\Server;
use App\Models\Subscription;
use App\Provisioning\ProvisioningService;
use App\Servers\ServerActionService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Support\Provisioning\ProvisioningFloor;

/**
 * Which subscription periods PostgreSQL will accept.
 *
 * Written against raw SQL rather than through the models, because the point of
 * a CHECK constraint is that it holds for the writes nobody reviewed — a future
 * service, a migration, somebody at a psql prompt. A rule enforced only in PHP
 * is a rule that stops existing the moment anything else writes the row.
 *
 * The rule is state-aware. A cancelled period may collapse to nothing, because
 * a customer who deleted a machine in the second it arrived genuinely had no
 * service. An active one may not: an active subscription that expires the
 * instant it begins is a customer who paid for thirty days and is owed none.
 */
beforeEach(function (): void {
    app(RoleProvisioner::class)->sync();

    $this->floor = ProvisioningFloor::open();
    app(ProvisioningService::class)->provision($this->floor->paidOrder());

    $this->subscription = Subscription::query()->sole();
});

/**
 * Try to write a period directly, in a savepoint.
 *
 * The constraint aborts the surrounding transaction when it fires, so each
 * attempt gets its own or the rest of the test dies with it.
 */
function writePeriod(int $subscriptionId, SubscriptionStatus $status, string $start, string $end): bool
{
    try {
        DB::transaction(function () use ($subscriptionId, $status, $start, $end): void {
            DB::table('subscriptions')->where('id', $subscriptionId)->update([
                'status' => $status->value,
                'current_period_start' => $start,
                'current_period_end' => $end,
                'updated_at' => now(),
            ]);
        });

        return true;
    } catch (QueryException) {
        return false;
    }
}

it('refuses a zero-length period for a status that is not cancelled', function (SubscriptionStatus $status): void {
    $at = '2026-03-01 12:00:00';

    expect(writePeriod((int) $this->subscription->getKey(), $status, $at, $at))->toBeFalse();

    // And the row is untouched.
    expect(Subscription::query()->sole()->periodSeconds())->toBe(Subscription::PERIOD_SECONDS);
})->with([
    'active' => [SubscriptionStatus::Active],
    'in grace' => [SubscriptionStatus::Grace],
    'needing attention' => [SubscriptionStatus::NeedsAttention],
    'terminated' => [SubscriptionStatus::Terminated],
]);

it('accepts a zero-length period only for a cancelled subscription', function (): void {
    $at = '2026-03-01 12:00:00';

    expect(writePeriod((int) $this->subscription->getKey(), SubscriptionStatus::Cancelled, $at, $at))->toBeTrue()
        ->and(Subscription::query()->sole()->periodSeconds())->toBe(0);
});

it('accepts an ordinary period for every status', function (SubscriptionStatus $status): void {
    expect(writePeriod(
        (int) $this->subscription->getKey(),
        $status,
        '2026-03-01 12:00:00',
        '2026-03-31 12:00:00',
    ))->toBeTrue();
})->with([
    'active' => [SubscriptionStatus::Active],
    'in grace' => [SubscriptionStatus::Grace],
    'cancelled' => [SubscriptionStatus::Cancelled],
    'needing attention' => [SubscriptionStatus::NeedsAttention],
    'terminated' => [SubscriptionStatus::Terminated],
]);

it('refuses an inverted period whatever the status', function (SubscriptionStatus $status): void {
    // The rule the original constraint was written for, and it still holds:
    // an end before a start is an inverted argument list, not a period.
    expect(writePeriod(
        (int) $this->subscription->getKey(),
        $status,
        '2026-03-31 12:00:00',
        '2026-03-01 12:00:00',
    ))->toBeFalse();
})->with([
    'active' => [SubscriptionStatus::Active],
    'in grace' => [SubscriptionStatus::Grace],
    'cancelled' => [SubscriptionStatus::Cancelled],
    'needing attention' => [SubscriptionStatus::NeedsAttention],
    'terminated' => [SubscriptionStatus::Terminated],
]);

it('still gives a new customer exactly thirty days', function (): void {
    expect($this->subscription->periodSeconds())->toBe(Subscription::PERIOD_SECONDS)
        ->and(Subscription::PERIOD_SECONDS)->toBe(2_592_000)
        ->and($this->subscription->status)->toBe(SubscriptionStatus::Active);
});

it('still lets a customer delete a server in the second it arrived', function (): void {
    // The case the previous migration existed for, and the one the state-aware
    // rule had to keep working: no service used, no service owed, and a
    // cancelled period that says so.
    $server = Server::query()->sole();

    $action = app(ServerActionService::class)->request(
        $this->floor->customer,
        $server->getKey(),
        ServerActionType::Delete,
        'same-second-delete',
    );

    app()->call([new App\Jobs\ExecuteServerActionJob((int) $action->getKey()), 'handle']);

    $subscription = Subscription::query()->sole();

    expect($subscription->status)->toBe(SubscriptionStatus::Cancelled)
        ->and($subscription->periodSeconds())->toBeGreaterThanOrEqual(0)
        ->and($subscription->current_period_end->timestamp)
        ->toBeLessThanOrEqual($server->fresh()->terminated_at->timestamp + 1);
});
