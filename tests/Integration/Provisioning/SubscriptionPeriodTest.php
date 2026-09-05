<?php

declare(strict_types=1);

use App\Authorization\RoleProvisioner;
use App\Models\Subscription;
use App\Provisioning\ProvisioningService;
use App\Provisioning\ReconciliationService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Tests\Support\Provisioning\ProvisioningFloor;

/**
 * A monthly period is exactly 2,592,000 seconds. Never a calendar month.
 *
 * The distinction is customer-visible money: under calendar arithmetic a
 * February customer would receive 28 days for the same price a March customer
 * pays for 31, and the rule for a subscription starting on the 31st is a
 * business decision nobody made. Recorded in docs/decisions/ADR-001.
 *
 * The dates below are chosen to expose calendar behaviour if it ever creeps
 * back in: each one lands somewhere a month-based implementation would give a
 * different answer.
 */
beforeEach(function (): void {
    app(RoleProvisioner::class)->sync();

    $this->provisioning = app(ProvisioningService::class);
});

/**
 * Open the shop at a given instant.
 *
 * The clock is set first, deliberately: the exchange rate is recorded when the
 * floor opens, and a rate stamped at the real wall clock would be either stale
 * or not yet in effect once the test moves to its chosen date.
 */
function floorAt(string $instant): ProvisioningFloor
{
    Date::setTestNow(CarbonImmutable::parse($instant));

    return ProvisioningFloor::open(walletBalance: 50_000_000);
}

afterEach(function (): void {
    Date::setTestNow();
});

it('adds exactly 2,592,000 seconds, whatever month it starts in', function (string $start, string $expected): void {
    $floor = floorAt($start);

    $this->provisioning->provision($floor->paidOrder());

    $subscription = Subscription::query()->latest('id')->firstOrFail();

    expect($subscription->periodSeconds())->toBe(2_592_000)
        ->and($subscription->current_period_start->toIso8601String())
        ->toBe(CarbonImmutable::parse($start)->toIso8601String())
        ->and($subscription->current_period_end->toIso8601String())
        ->toBe(CarbonImmutable::parse($expected)->toIso8601String());
})->with([
    // February in a leap year: a calendar month would give 29 days here.
    'leap february' => ['2028-02-01T00:00:00Z', '2028-03-02T00:00:00Z'],
    // A short February: a calendar month would give 28.
    'short february' => ['2027-02-01T12:34:56Z', '2027-03-03T12:34:56Z'],
    // A 31-day month: a calendar month would give 31.
    'january' => ['2026-01-15T08:00:00Z', '2026-02-14T08:00:00Z'],
    // The 31st, where an anniversary rule has to invent an answer.
    'the thirty-first' => ['2026-01-31T23:59:59Z', '2026-03-02T23:59:59Z'],
    // Across a year boundary.
    'year end' => ['2026-12-20T06:00:00Z', '2027-01-19T06:00:00Z'],
    // A 30-day month, where the two rules happen to agree — which is the
    // coincidence that makes a calendar bug easy to miss.
    'april' => ['2026-04-10T00:00:00Z', '2026-05-10T00:00:00Z'],
]);

it('never lands on the same wall-clock day twice in a row', function (): void {
    // Twelve consecutive periods are 360 days, not a year. That drift is the
    // accepted, deliberate consequence recorded in ADR-001, and asserting it
    // here stops somebody "fixing" it into calendar arithmetic later.
    $start = CarbonImmutable::parse('2026-01-01T00:00:00Z');
    $end = $start;

    foreach (range(1, 12) as $ignored) {
        $end = $end->addSeconds(Subscription::PERIOD_SECONDS);
    }

    expect($end->toIso8601String())->toBe('2026-12-27T00:00:00+00:00')
        ->and($end->getTimestamp() - $start->getTimestamp())->toBe(12 * 2_592_000);
});

it('does not move the period when a duplicate recovery runs', function (): void {
    $floor = floorAt('2026-02-27T10:00:00Z');

    $order = $floor->paidOrder();
    $this->provisioning->provision($order);

    $subscription = Subscription::query()->firstOrFail();
    $start = $subscription->current_period_start->toIso8601String();
    $end = $subscription->current_period_end->toIso8601String();

    // Time passes, and the work is repeated by a duplicated job and by the
    // sweeper. A customer's 30 days do not restart because a worker ran twice.
    Date::setTestNow(CarbonImmutable::parse('2026-03-05T10:00:00Z'));

    $this->provisioning->provision($order->fresh());
    app(ReconciliationService::class)->reconcile($order->fresh());
    $this->provisioning->provision($order->fresh());

    $after = Subscription::query()->firstOrFail();

    expect(Subscription::query()->count())->toBe(1)
        ->and($after->current_period_start->toIso8601String())->toBe($start)
        ->and($after->current_period_end->toIso8601String())->toBe($end)
        ->and($after->periodSeconds())->toBe(2_592_000);
});

it('starts service at the same instant the order records as provisioned', function (): void {
    $floor = floorAt('2026-06-15T09:30:00Z');

    $order = $floor->paidOrder();
    $this->provisioning->provision($order);

    $subscription = Subscription::query()->firstOrFail();

    // One instant, written to both. Two clocks would eventually disagree about
    // when a customer's service began.
    expect($subscription->current_period_start->toIso8601String())
        ->toBe($order->fresh()->provisioned_at->toIso8601String());
});

it('states the period length as a constant rather than a calculation', function (): void {
    expect(Subscription::PERIOD_SECONDS)->toBe(2_592_000)
        ->and(Subscription::PERIOD_SECONDS)->toBe(30 * 24 * 3600);
});

it('refuses a period that ends before it starts', function (): void {
    $this->provisioning->provision(ProvisioningFloor::open()->paidOrder());
    $subscription = Subscription::query()->firstOrFail();

    expect(fn () => Illuminate\Support\Facades\DB::transaction(
        fn () => Illuminate\Support\Facades\DB::table('subscriptions')
            ->where('id', $subscription->id)
            ->update(['current_period_end' => $subscription->current_period_start->copy()->subDay()]),
    ))->toThrow(Illuminate\Database\QueryException::class);
});
