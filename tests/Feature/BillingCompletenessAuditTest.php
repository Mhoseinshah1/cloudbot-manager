<?php

use App\Exceptions\InsufficientWalletBalanceException;
use App\Jobs\ProvisionServerJob;
use App\Models\LowBalanceWarning;
use App\Models\Order;
use App\Models\Product;
use App\Models\Server;
use App\Models\ServerBillingPeriod;
use App\Models\Setting;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Services\HourlyBillingService;
use App\Services\OrderService;
use App\Services\PaymentService;
use App\Services\ServerActionService;
use App\Services\WalletService;
use Database\Seeders\FakeProviderSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Final completeness audit of the billing core. Each group maps to one
 * requirement of the audit checklist. Existing coverage lives in
 * HourlyBillingTest / WalletTest / ProductBillingValidatorTest; this file
 * only adds the scenarios that were missing.
 */
beforeEach(function () {
    $this->seed(FakeProviderSeeder::class);
    $this->user = User::factory()->create();

    config()->set('billing.hourly.minimum_prepaid_hours', 1);
    config()->set('billing.hourly.grace_hours', 48);
    config()->set('billing.hourly.lifecycle_action', 'notify_only');
});

afterEach(function () {
    Carbon::setTestNow();
});

/**
 * Distinct helper name — HourlyBillingTest already defines provisionHourlyServer.
 */
function auditProvisionServer(User $user, string $slug = 'vps-cx21-hourly'): Server
{
    $product = Product::query()->where('slug', $slug)->firstOrFail();

    $order = app(OrderService::class)->place($user, $product);
    $invoice = app(OrderService::class)->createInvoice($order, 'manual');
    $payment = app(PaymentService::class)->start($invoice, 'manual');
    app(PaymentService::class)->confirm($payment, ['approved' => true], $user);
    app(PaymentService::class)->provision($order->fresh());

    return $order->fresh()->server;
}

// ---------------------------------------------------------------------------
// 1. Hourly_capped service period — 30/31-day transitions
// ---------------------------------------------------------------------------

it('keeps the cap period on a 30-day start month and resets only at the real boundary', function () {
    // April has 30 days; the first cap period is Apr 30 15:00 → May 30 15:00.
    Carbon::setTestNow('2026-04-30 15:00:00');

    $server = auditProvisionServer($this->user, 'vps-cx21-capped');

    expect($server->billing_period_started_at->toDateTimeString())->toBe('2026-04-30 15:00:00');
    expect($server->billing_period_ends_at->toDateTimeString())->toBe('2026-05-30 15:00:00');

    app(WalletService::class)->credit($this->user, 1000000);
    $server->update(['monthly_cap_toman' => 4000]);

    // Crossing the May calendar boundary does NOT reset the exhausted cap.
    Carbon::setTestNow('2026-05-01 00:00:00');
    app(HourlyBillingService::class)->processServer($server->fresh());

    $server = $server->fresh();
    expect($server->billing_period_started_at->toDateTimeString())->toBe('2026-04-30 15:00:00');
    expect($server->current_period_charged)->toBe(4000);
    expect((int) $server->billingPeriods()->sum('amount_toman'))->toBe(4000);

    // Advancing past the actual service boundary starts a fresh cap period.
    Carbon::setTestNow('2026-05-30 15:01:00');
    app(HourlyBillingService::class)->processServer($server);

    $server = $server->fresh();
    expect($server->billing_period_started_at->toDateTimeString())->toBe('2026-05-30 15:00:00');
    expect($server->billing_period_ends_at->toDateTimeString())->toBe('2026-06-30 15:00:00');
    expect($server->current_period_charged)->toBe(850);
    expect((int) $server->billingPeriods()->sum('amount_toman'))->toBe(4850);
});

// ---------------------------------------------------------------------------
// 2. Minimum prepaid balance
// ---------------------------------------------------------------------------

it('rejects provisioning when the wallet cannot cover the minimum prepaid balance', function () {
    config()->set('billing.hourly.minimum_prepaid_hours', 24);

    $product = Product::query()->where('slug', 'vps-cx21-hourly')->firstOrFail();

    $order = app(OrderService::class)->place($this->user, $product);
    expect($order->total_toman)->toBe(24 * 850); // the full prepaid requirement

    $invoice = app(OrderService::class)->createInvoice($order, 'manual');
    $payment = app(PaymentService::class)->start($invoice, 'manual');
    app(PaymentService::class)->confirm($payment, ['approved' => true], $this->user);
    expect($this->user->fresh()->wallet->balance_toman)->toBe(24 * 850);

    // The balance is spent elsewhere before the queue runs provisioning.
    app(WalletService::class)->debit($this->user, 10000, description: 'spent elsewhere');

    expect(fn () => ProvisionServerJob::dispatchSync($order->fresh()))
        ->toThrow(InsufficientWalletBalanceException::class);

    // No provider call was made and the order remains payable/retryable.
    $order->refresh();
    expect($order->status)->toBe(Order::STATUS_PAID);
    expect($order->server()->exists())->toBeFalse();
    expect(Server::query()->count())->toBe(0);
    expect($this->user->fresh()->wallet->balance_toman)->toBe(24 * 850 - 10000);
});

it('accepts provisioning when the wallet holds exactly the minimum prepaid balance', function () {
    config()->set('billing.hourly.minimum_prepaid_hours', 1);

    $server = auditProvisionServer($this->user);

    expect($server)->not->toBeNull();
    expect($this->user->fresh()->wallet->balance_toman)->toBe(850); // exactly 1h × 850
});

it('accepts provisioning when the wallet is already funded — the top-up covers only the shortfall', function () {
    config()->set('billing.hourly.minimum_prepaid_hours', 24);
    $product = Product::query()->where('slug', 'vps-cx21-hourly')->firstOrFail();

    app(WalletService::class)->credit($this->user, 20000);

    $order = app(OrderService::class)->place($this->user, $product);

    // Shortfall is 400 toman, but never less than one hour of usage.
    expect($order->total_toman)->toBe(850);

    $invoice = app(OrderService::class)->createInvoice($order, 'manual');
    $payment = app(PaymentService::class)->start($invoice, 'manual');
    app(PaymentService::class)->confirm($payment, ['approved' => true], $this->user);
    app(PaymentService::class)->provision($order->fresh());

    expect($order->fresh()->status)->toBe(Order::STATUS_PROVISIONED);
    expect($this->user->fresh()->wallet->balance_toman)->toBe(20850);
});

it('treats the initial payment as wallet funding — never a usage charge', function () {
    $server = auditProvisionServer($this->user);

    expect($server->billingPeriods()->count())->toBe(0);
    expect($server->last_billed_at)->toBeNull();

    $wallet = $this->user->fresh()->wallet;
    expect($wallet->transactions()->where('type', WalletTransaction::TYPE_CREDIT)->count())->toBe(1);
    expect($wallet->transactions()->where('type', WalletTransaction::TYPE_DEBIT)->count())->toBe(0);
});

it('does not double charge between the order payment and hourly usage billing', function () {
    $server = auditProvisionServer($this->user); // wallet funded with 850

    Carbon::setTestNow($server->billing_started_at->copy()->addMinutes(5));
    app(HourlyBillingService::class)->processServer($server->fresh());

    $wallet = $this->user->fresh()->wallet;
    expect((int) $wallet->transactions()->where('type', WalletTransaction::TYPE_CREDIT)->sum('amount_toman'))->toBe(850);
    expect((int) $wallet->transactions()->where('type', WalletTransaction::TYPE_DEBIT)->sum('amount_toman'))->toBe(850);
    expect((int) $server->fresh()->billingPeriods()->sum('amount_toman'))->toBe(850); // one hour, once
    expect($wallet->balance_toman)->toBe(0);
});

// ---------------------------------------------------------------------------
// 3. Rate snapshot immutability
// ---------------------------------------------------------------------------

it('keeps the snapshot rate of a running VPS when the product price changes', function () {
    $server = auditProvisionServer($this->user); // snapshotted at 850
    app(WalletService::class)->credit($this->user, 100000);

    $server->product->update(['hourly_price_toman' => 1200]);

    Carbon::setTestNow($server->billing_started_at->copy()->addHour()->addMinutes(5));
    app(HourlyBillingService::class)->processServer($server->fresh());

    $period = $server->fresh()->billingPeriods()->first();

    expect($server->fresh()->hourly_rate_toman)->toBe(850);
    expect($period->rate_toman)->toBe(850);
    expect($period->amount_toman)->toBe(850);
});

it('bills a NEW VPS at the updated product price', function () {
    $product = Product::query()->where('slug', 'vps-cx21-hourly')->firstOrFail();
    $product->update(['hourly_price_toman' => 1200]);

    $server = auditProvisionServer($this->user);
    app(WalletService::class)->credit($this->user, 100000);

    expect($server->hourly_rate_toman)->toBe(1200);

    Carbon::setTestNow($server->billing_started_at->copy()->addHour()->addMinutes(5));
    app(HourlyBillingService::class)->processServer($server->fresh());

    expect($server->fresh()->billingPeriods()->first()->amount_toman)->toBe(1200);
});

it('snapshots the hourly_capped cap per service', function () {
    $old = auditProvisionServer($this->user, 'vps-cx21-capped'); // cap 399,000
    expect($old->monthly_cap_toman)->toBe(399000);

    Product::query()->where('slug', 'vps-cx21-capped')->firstOrFail()->update(['monthly_cap_toman' => 500000]);

    $new = auditProvisionServer($this->user, 'vps-cx21-capped');

    expect($new->monthly_cap_toman)->toBe(500000);
    expect($old->fresh()->monthly_cap_toman)->toBe(399000);
});

// ---------------------------------------------------------------------------
// 4. Termination finalization
// ---------------------------------------------------------------------------

it('finalizes billing at termination and never charges a stopped server days later', function () {
    $server = auditProvisionServer($this->user);
    app(WalletService::class)->credit($this->user, 100000);
    $balanceBefore = $this->user->fresh()->wallet->balance_toman; // 100,850

    Carbon::setTestNow($server->billing_started_at->copy()->addMinutes(25));
    app(ServerActionService::class)->perform($server->fresh(), 'delete', $this->user);

    $server = Server::query()->withTrashed()->find($server->id);

    expect($server->billing_stopped_at)->not->toBeNull();
    expect($server->status)->toBe(Server::STATUS_DELETED);
    expect($server->billingPeriods()->count())->toBe(1); // final partial hour (ceil)
    expect((int) $server->billingPeriods()->sum('amount_toman'))->toBe(850);
    expect($server->subscription)->not->toBeNull(); // subscription history preserved
    expect($server->billingPeriods()->first()->reference_type)->toBe(WalletTransaction::class);

    // Days later the scheduler processes due servers — zero additional charges,
    // ledger and wallet history are preserved.
    Carbon::setTestNow($server->billing_started_at->copy()->addDays(3));
    app(HourlyBillingService::class)->processDueServers();

    $server = Server::query()->withTrashed()->find($server->id);
    expect($server->billingPeriods()->count())->toBe(1);
    expect($this->user->fresh()->wallet->balance_toman)->toBe($balanceBefore - 850);
    expect($this->user->fresh()->wallet->transactions()->count())->toBe(3); // 2 credits + 1 debit
});

it('is safe to finalize billing twice (stopBilling is idempotent)', function () {
    $server = auditProvisionServer($this->user);
    app(WalletService::class)->credit($this->user, 100000);

    Carbon::setTestNow($server->billing_started_at->copy()->addMinutes(25));
    app(ServerActionService::class)->perform($server->fresh(), 'delete', $this->user);

    $stopped = Server::query()->withTrashed()->find($server->id);
    $stoppedAt = $stopped->billing_stopped_at;
    $debits = $this->user->fresh()->wallet->transactions()->where('type', WalletTransaction::TYPE_DEBIT)->count();

    app(HourlyBillingService::class)->stopBilling($stopped);

    $stopped = Server::query()->withTrashed()->find($server->id);
    expect($stopped->billing_stopped_at->equalTo($stoppedAt))->toBeTrue();
    expect($stopped->billingPeriods()->count())->toBe(1);
    expect($this->user->fresh()->wallet->transactions()->where('type', WalletTransaction::TYPE_DEBIT)->count())->toBe($debits);
});

// ---------------------------------------------------------------------------
// 5. Hourly E2E
// ---------------------------------------------------------------------------

it('completes the full hourly lifecycle end-to-end', function () {
    // Fund → order → payment (wallet funding, not a usage charge).
    $product = Product::query()->where('slug', 'vps-cx21-hourly')->firstOrFail();
    $order = app(OrderService::class)->place($this->user, $product);
    $invoice = app(OrderService::class)->createInvoice($order, 'manual');
    $payment = app(PaymentService::class)->start($invoice, 'manual');
    app(PaymentService::class)->confirm($payment, ['approved' => true], $this->user);
    app(PaymentService::class)->provision($order->fresh());

    $server = $order->fresh()->server;
    $startedAt = $server->billing_started_at;
    expect($server->billingPeriods()->count())->toBe(0);

    // Top-up so 5 hours of usage settle from the wallet.
    app(WalletService::class)->credit($this->user, 100000);
    $balanceStart = $this->user->fresh()->wallet->balance_toman; // 100,850

    // 5 hours of usage → exactly 5 × 850, all integer toman.
    Carbon::setTestNow($startedAt->copy()->addHours(5));
    expect(app(HourlyBillingService::class)->processServer($server->fresh()))->toBe(5);

    $server = $server->fresh();
    expect($server->billingPeriods()->count())->toBe(5);
    expect((int) $server->billingPeriods()->sum('amount_toman'))->toBe(4250);
    expect($server->billingPeriods()->first()->amount_toman)->toBeInt();
    expect($this->user->fresh()->wallet->balance_toman)->toBeInt();
    expect($this->user->fresh()->wallet->balance_toman)->toBe($balanceStart - 4250);

    // Rerunning the same timestamp charges nothing.
    expect(app(HourlyBillingService::class)->processServer($server))->toBe(0);
    expect($server->fresh()->billingPeriods()->count())->toBe(5);
    expect($this->user->fresh()->wallet->balance_toman)->toBe($balanceStart - 4250);

    // power_off is a server action — hourly billing continues.
    app(ServerActionService::class)->perform($server, 'power_off', $this->user);

    Carbon::setTestNow($startedAt->copy()->addHours(6));
    app(HourlyBillingService::class)->processServer($server->fresh());

    $server = $server->fresh();
    expect($server->power_state)->toBe('off');
    expect($server->billing_stopped_at)->toBeNull();
    expect($server->billingPeriods()->count())->toBe(6);
    expect($this->user->fresh()->wallet->balance_toman)->toBe($balanceStart - 5100);

    // Terminate: the in-flight partial hour is settled per the ceil policy.
    Carbon::setTestNow($startedAt->copy()->addHours(6)->addMinutes(25));
    app(ServerActionService::class)->perform($server, 'delete', $this->user);

    $server = Server::query()->withTrashed()->find($server->id);
    expect($server->billing_stopped_at)->not->toBeNull();
    expect($server->billingPeriods()->count())->toBe(7);
    expect((int) $server->billingPeriods()->sum('amount_toman'))->toBe(5950);
    expect($this->user->fresh()->wallet->balance_toman)->toBe($balanceStart - 5950);

    // Future processing never charges a stopped server.
    Carbon::setTestNow($startedAt->copy()->addDays(2));
    app(HourlyBillingService::class)->processDueServers();

    $server = Server::query()->withTrashed()->find($server->id);
    expect($server->billingPeriods()->count())->toBe(7);
    expect($this->user->fresh()->wallet->balance_toman)->toBe($balanceStart - 5950);
});

// ---------------------------------------------------------------------------
// 6. Hourly_capped E2E — covered by HourlyBillingTest:
//    'stops charging at the cap and resumes when the service cap period
//    advances' + 'does not reset the cap at a calendar-month boundary while
//    the service period is open' together cover the full capped lifecycle.
// ---------------------------------------------------------------------------

// ---------------------------------------------------------------------------
// 7. Ledger link integrity
// ---------------------------------------------------------------------------

it('prevents a duplicate interval reference from creating another debit', function () {
    $server = auditProvisionServer($this->user);
    app(WalletService::class)->credit($this->user, 100000);
    $startedAt = $server->billing_started_at;

    // A ledger row for the first interval already exists (concurrent run).
    ServerBillingPeriod::query()->create([
        'server_id' => $server->id,
        'subscription_id' => $server->subscription?->id,
        'period_start' => $startedAt,
        'period_end' => $startedAt->copy()->addHour(),
        'rate_toman' => 850,
        'amount_toman' => 850,
        'currency' => ServerBillingPeriod::CURRENCY_IRR,
        'status' => ServerBillingPeriod::STATUS_UNPAID,
    ]);

    Carbon::setTestNow($startedAt->copy()->addMinutes(5));
    app(HourlyBillingService::class)->processServer($server->fresh());

    $server = $server->fresh();
    expect($server->billingPeriods()->count())->toBe(1);
    expect($this->user->fresh()->wallet->transactions()->where('type', WalletTransaction::TYPE_DEBIT)->count())->toBe(0);
    expect($this->user->fresh()->wallet->balance_toman)->toBe(100850);
});

it('links every billing period to its exact wallet transaction, per server', function () {
    $serverA = auditProvisionServer($this->user);
    $serverB = auditProvisionServer($this->user);
    app(WalletService::class)->credit($this->user, 100000);

    // Force identical billing anchors so both servers own the same interval.
    $serverB->update([
        'billing_started_at' => $serverA->billing_started_at,
        'last_billed_at' => null,
    ]);

    Carbon::setTestNow($serverA->billing_started_at->copy()->addMinutes(5));
    app(HourlyBillingService::class)->processServer($serverA->fresh());
    app(HourlyBillingService::class)->processServer($serverB->fresh());

    $periodA = $serverA->fresh()->billingPeriods()->first();
    $periodB = $serverB->fresh()->billingPeriods()->first();

    expect($periodA->period_start->equalTo($periodB->period_start))->toBeTrue();
    expect($periodA->period_end->equalTo($periodB->period_end))->toBeTrue();
    expect($periodA->id)->not->toBe($periodB->id); // no cross-server collision
    expect($periodA->reference_id)->not->toBe($periodB->reference_id); // distinct debits

    // Deterministic round-trip: billing period → wallet transaction → period.
    foreach ([$periodA, $periodB] as $period) {
        expect($period->reference_type)->toBe(WalletTransaction::class);
        expect($period->reference_id)->not->toBeNull();

        $tx = WalletTransaction::query()->find($period->reference_id);
        expect($tx)->not->toBeNull();
        expect($tx->reference_type)->toBe(ServerBillingPeriod::class);
        expect($tx->reference_id)->toBe($period->id);
    }

    expect($this->user->fresh()->wallet->balance_toman)->toBe(100000); // 1700 funded + 100000 − 2×850
});

// ---------------------------------------------------------------------------
// 8. Multiple services / same wallet
// ---------------------------------------------------------------------------

it('charges two hourly services on one wallet independently without collisions', function () {
    $serverA = auditProvisionServer($this->user);
    $serverB = auditProvisionServer($this->user);
    app(WalletService::class)->credit($this->user, 100000); // balance 101,700

    Carbon::setTestNow($serverA->billing_started_at->copy()->addHours(5));
    $processed = app(HourlyBillingService::class)->processDueServers();

    expect($processed)->toBe(10); // 5 units × 2 servers
    expect($serverA->fresh()->billingPeriods()->count())->toBe(5);
    expect($serverB->fresh()->billingPeriods()->count())->toBe(5);

    $periods = ServerBillingPeriod::query()
        ->whereIn('server_id', [$serverA->id, $serverB->id])
        ->get();

    expect((int) $periods->sum('amount_toman'))->toBe(8500);
    expect($periods->where('status', ServerBillingPeriod::STATUS_PAID)->count())->toBe(10);
    expect($periods->pluck('reference_id')->unique()->count())->toBe(10); // one debit per interval

    $wallet = $this->user->fresh()->wallet;
    expect($wallet->balance_toman)->toBe(101700 - 8500);
    expect($wallet->balance_toman)->toBeGreaterThanOrEqual(0);
});

// ---------------------------------------------------------------------------
// 9. Money safety — rounding determinism (integer math is covered by the
//    wallet/ledger assertions above and WalletTest)
// ---------------------------------------------------------------------------

it('applies floor and round rounding policies deterministically on the unit grid', function () {
    $server = auditProvisionServer($this->user);
    $service = app(HourlyBillingService::class);
    $startedAt = $server->billing_started_at;

    $cases = [
        ['floor', 5, 0],
        ['floor', 65, 1],
        ['round', 65, 1],
        ['round', 95, 2],
        ['ceil', 95, 2],
    ];

    foreach ($cases as [$policy, $minutes, $units]) {
        Setting::query()->updateOrCreate(
            ['key' => 'billing.hourly_rounding'],
            ['value' => $policy, 'group' => 'billing']
        );

        $until = $service->chargeableUntil($server->fresh(), $startedAt->copy()->addMinutes($minutes));
        $expected = $startedAt->copy()->addMinutes($units * 60);

        expect($until->equalTo($expected))->toBeTrue();

        // Deterministic: repeating the computation yields the same boundary.
        $again = $service->chargeableUntil($server->fresh(), $startedAt->copy()->addMinutes($minutes));
        expect($again->equalTo($until))->toBeTrue();
    }
});

it('does not bill a partial hour when the floor rounding policy is configured', function () {
    Setting::query()->updateOrCreate(
        ['key' => 'billing.hourly_rounding'],
        ['value' => 'floor', 'group' => 'billing']
    );

    $server = auditProvisionServer($this->user);

    Carbon::setTestNow($server->billing_started_at->copy()->addMinutes(5));
    $recorded = app(HourlyBillingService::class)->processServer($server->fresh());

    expect($recorded)->toBe(0);
    expect($server->fresh()->billingPeriods()->count())->toBe(0);
    expect($server->fresh()->last_billed_at)->toBeNull();
    expect($this->user->fresh()->wallet->balance_toman)->toBe(850); // untouched
});

// ---------------------------------------------------------------------------
// 10. February cap period — non-leap-year 28-day first period
// ---------------------------------------------------------------------------

it('anchors a February-start cap period to exactly 28 days in a non-leap year', function () {
    // 2026 is not a leap year. Service starts Feb 1; first cap period is
    // Feb 1 → Mar 1 (28 days), not Feb 1 → Mar 31 (overflow).
    Carbon::setTestNow('2026-02-01 10:00:00');

    $server = auditProvisionServer($this->user, 'vps-cx21-capped');

    expect($server->billing_period_started_at->toDateTimeString())->toBe('2026-02-01 10:00:00');
    expect($server->billing_period_ends_at->toDateTimeString())->toBe('2026-03-01 10:00:00');

    app(WalletService::class)->credit($this->user, 1000000);
    $server->update(['monthly_cap_toman' => 4000]);

    // Exhaust the cap on Feb 28.
    Carbon::setTestNow('2026-02-28 20:00:00');
    app(HourlyBillingService::class)->processServer($server->fresh());

    expect($server->fresh()->billing_period_started_at->toDateTimeString())->toBe('2026-02-01 10:00:00');
    expect($server->fresh()->billing_period_ends_at->toDateTimeString())->toBe('2026-03-01 10:00:00');
    expect($server->fresh()->current_period_charged)->toBe(4000);

    // Crossing Mar 1 advances to the next service period (Mar 1 → Apr 1,
    // because addMonthNoOverflow on Mar 1 adds exactly one calendar month).
    Carbon::setTestNow('2026-03-01 11:00:00');
    app(HourlyBillingService::class)->processServer($server->fresh());

    $server = $server->fresh();
    expect($server->billing_period_started_at->toDateTimeString())->toBe('2026-03-01 10:00:00');
    expect($server->billing_period_ends_at->toDateTimeString())->toBe('2026-04-01 10:00:00');
    expect($server->current_period_charged)->toBe(850);
    expect((int) $server->billingPeriods()->sum('amount_toman'))->toBe(4850);
});

// ---------------------------------------------------------------------------
// 11. Zero-rate guard
// ---------------------------------------------------------------------------

it('skips billing for a server with zero hourly rate without errors', function () {
    $server = auditProvisionServer($this->user);
    $server->update(['hourly_rate_toman' => 0]);
    app(WalletService::class)->credit($this->user, 10000);

    Carbon::setTestNow($server->fresh()->billing_started_at->copy()->addHours(5));
    $recorded = app(HourlyBillingService::class)->processServer($server->fresh());

    expect($recorded)->toBe(0);
    expect($server->fresh()->billingPeriods()->count())->toBe(0);
    expect($server->fresh()->last_billed_at)->toBeNull();
    expect($this->user->fresh()->wallet->balance_toman)->toBe(10850); // 850 from provisioning + 10000
});

// ---------------------------------------------------------------------------
// 12. Billing config defaults are loadable
// ---------------------------------------------------------------------------

it('has sensible billing config defaults', function () {
    expect(config('billing.hourly.minimum_prepaid_hours'))->toBeInt();
    expect(config('billing.hourly.minimum_prepaid_hours'))->toBeGreaterThan(0);
    expect(config('billing.hourly.grace_hours'))->toBeInt();
    expect(config('billing.hourly.grace_hours'))->toBeGreaterThan(0);
    expect(config('billing.hourly.lifecycle_action'))->toBeIn([
        HourlyBillingService::LIFECYCLE_NOTIFY_ONLY,
        HourlyBillingService::LIFECYCLE_POWER_OFF,
        HourlyBillingService::LIFECYCLE_TERMINATE,
    ]);
    expect(config('billing.hourly_rounding'))->toBeIn([
        HourlyBillingService::ROUNDING_CEIL,
        HourlyBillingService::ROUNDING_FLOOR,
        HourlyBillingService::ROUNDING_ROUND,
    ]);
    expect(config('billing.hourly.low_balance_warning_hours'))->toBeArray();
    expect(config('billing.hourly.low_balance_warning_hours'))->not->toBeEmpty();
});

// ---------------------------------------------------------------------------
// 13. PostgreSQL-aware regression: duplicate billing interval
// ---------------------------------------------------------------------------

it('handles a pre-existing duplicate billing interval without aborting the transaction', function () {
    $server = auditProvisionServer($this->user);
    app(WalletService::class)->credit($this->user, 100000);
    $startedAt = $server->billing_started_at;

    // Simulate a concurrent processor that already recorded this interval.
    ServerBillingPeriod::query()->create([
        'server_id' => $server->id,
        'subscription_id' => $server->subscription?->id,
        'period_start' => $startedAt,
        'period_end' => $startedAt->copy()->addHour(),
        'rate_toman' => 850,
        'amount_toman' => 850,
        'currency' => ServerBillingPeriod::CURRENCY_IRR,
        'status' => ServerBillingPeriod::STATUS_PAID,
    ]);

    // The pre-check in recordPeriod should detect the existing row and
    // return null without ever hitting the unique constraint — critical
    // on PostgreSQL where an aborted transaction makes subsequent writes
    // in the same transaction fail with SQLSTATE[25P02].
    Carbon::setTestNow($startedAt->copy()->addMinutes(5));
    app(HourlyBillingService::class)->processServer($server->fresh());

    $server = $server->fresh();
    expect($server->billingPeriods()->count())->toBe(1);
    expect($server->last_billed_at)->not->toBeNull(); // cursor advanced
    expect($this->user->fresh()->wallet->balance_toman)->toBe(100850);
    // No wallet debit was created for the duplicate interval.
    expect($this->user->fresh()->wallet->transactions()->where('type', WalletTransaction::TYPE_DEBIT)->count())->toBe(0);

    // Crucially, the transaction is still usable — a second call succeeds.
    Carbon::setTestNow($startedAt->copy()->addHours(2)->addMinutes(5));
    app(HourlyBillingService::class)->processServer($server);

    expect($server->fresh()->billingPeriods()->count())->toBe(3); // 1 pre-existing + 2 new
});

// ---------------------------------------------------------------------------
// 14. PostgreSQL-aware regression: warning lifecycle
// ---------------------------------------------------------------------------

it('resolves a warning on replenishment and creates a new one on the next dip', function () {
    $server = auditProvisionServer($this->user);
    $service = app(HourlyBillingService::class);
    $startedAt = $server->billing_started_at;

    // Charge the first hour — wallet empties, warnings fire.
    Carbon::setTestNow($startedAt->copy()->addMinutes(5));
    $service->processServer($server->fresh());

    $unresolved = LowBalanceWarning::query()
        ->where('server_id', $server->id)
        ->unresolved()
        ->count();
    expect($unresolved)->toBe(3);

    // Replenish — all warnings resolve.
    app(WalletService::class)->credit($this->user, 100000);

    Carbon::setTestNow($startedAt->copy()->addMinutes(10));
    $service->processServer($server->fresh());

    expect(LowBalanceWarning::query()->where('server_id', $server->id)->unresolved()->count())->toBe(0);

    // Drain the balance again — a fresh unresolved warning should appear
    // for the 24h threshold (the old one was resolved, not deleted).
    Wallet::query()->where('user_id', $this->user->id)->update(['balance_toman' => 100]);

    Carbon::setTestNow($startedAt->copy()->addMinutes(20));
    $service->processServer($server->fresh());

    $fresh24h = LowBalanceWarning::query()
        ->where('server_id', $server->id)
        ->where('threshold_hours', 24)
        ->unresolved()
        ->count();
    expect($fresh24h)->toBe(1);
});

// ---------------------------------------------------------------------------
// 15. PostgreSQL-aware regression: concurrent duplicate warning
// ---------------------------------------------------------------------------

it('creates at most one unresolved warning when attempted concurrently', function () {
    $server = auditProvisionServer($this->user);
    $service = app(HourlyBillingService::class);
    $startedAt = $server->billing_started_at;

    Carbon::setTestNow($startedAt->copy()->addMinutes(5));

    // First run creates warnings.
    $service->processServer($server->fresh());

    $count = LowBalanceWarning::query()
        ->where('server_id', $server->id)
        ->where('threshold_hours', 24)
        ->unresolved()
        ->count();
    expect($count)->toBe(1);

    // Second run at the same moment should be a no-op for warnings.
    $service->processServer($server->fresh());

    $count = LowBalanceWarning::query()
        ->where('server_id', $server->id)
        ->where('threshold_hours', 24)
        ->unresolved()
        ->count();
    expect($count)->toBe(1);
});

// ---------------------------------------------------------------------------
// 16. Aggregate money assertions are strict integers
// ---------------------------------------------------------------------------

it('returns integer sums from aggregate queries after explicit cast', function () {
    $server = auditProvisionServer($this->user);
    app(WalletService::class)->credit($this->user, 100000);

    // 55 minutes in = ceil(55min) = 1 unit = 850 toman
    Carbon::setTestNow($server->billing_started_at->copy()->addMinutes(55));
    app(HourlyBillingService::class)->processServer($server->fresh());

    $sum = (int) $server->fresh()->billingPeriods()->sum('amount_toman');
    expect($sum)->toBeInt();
    expect($sum)->toBe(850);

    $walletDebitSum = (int) $this->user->fresh()->wallet->transactions()->where('type', WalletTransaction::TYPE_DEBIT)->sum('amount_toman');
    expect($walletDebitSum)->toBeInt();
    expect($walletDebitSum)->toBe(850);
});

// ---------------------------------------------------------------------------
// 17. Atomic concurrency safety — billing period
// ---------------------------------------------------------------------------

it('uses conflict-safe insert for billing periods — duplicate is silently ignored', function () {
    $server = auditProvisionServer($this->user);
    app(WalletService::class)->credit($this->user, 100000);
    $startedAt = $server->billing_started_at;

    $periodStart = $startedAt->copy();
    $periodEnd = $startedAt->copy()->addHour();

    // First atomic insert succeeds.
    $inserted1 = DB::table('server_billing_periods')->insertOrIgnore([
        'server_id' => $server->id,
        'subscription_id' => $server->subscription?->id,
        'period_start' => $periodStart,
        'period_end' => $periodEnd,
        'rate_toman' => 850,
        'amount_toman' => 850,
        'currency' => 'IRR',
        'status' => 'unpaid',
        'capped' => false,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    expect($inserted1)->toBe(1);

    // Second identical insert is silently ignored (no exception, no abort).
    $inserted2 = DB::table('server_billing_periods')->insertOrIgnore([
        'server_id' => $server->id,
        'subscription_id' => $server->subscription?->id,
        'period_start' => $periodStart,
        'period_end' => $periodEnd,
        'rate_toman' => 850,
        'amount_toman' => 850,
        'currency' => 'IRR',
        'status' => 'unpaid',
        'capped' => false,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    expect($inserted2)->toBe(0);

    // Transaction is still usable — UPDATE succeeds.
    DB::table('server_billing_periods')
        ->where('server_id', $server->id)
        ->where('period_start', $periodStart)
        ->update(['status' => 'paid']);

    // Only one billing period exists.
    expect(ServerBillingPeriod::query()->where('server_id', $server->id)->count())->toBe(1);
    expect(ServerBillingPeriod::query()->where('server_id', $server->id)->first()->status)->toBe('paid');

    // Only one wallet debit for the interval — the billing service uses
    // the same atomic pattern, so running processServer is also safe.
    Carbon::setTestNow($startedAt->copy()->addMinutes(5));
    app(HourlyBillingService::class)->processServer($server->fresh());

    expect(ServerBillingPeriod::query()->where('server_id', $server->id)->count())->toBe(1);
    expect($this->user->fresh()->wallet->transactions()->where('type', WalletTransaction::TYPE_DEBIT)->count())->toBe(0);
    // Balance untouched — the pre-inserted row blocked the billing service.
    expect($this->user->fresh()->wallet->balance_toman)->toBe(100850);
});

// ---------------------------------------------------------------------------
// 18. Atomic concurrency safety — low balance warning
// ---------------------------------------------------------------------------

it('uses conflict-safe insert for warnings — duplicate is silently ignored then re-triggers after resolve', function () {
    $server = auditProvisionServer($this->user);
    $service = app(HourlyBillingService::class);
    $startedAt = $server->billing_started_at;

    Carbon::setTestNow($startedAt->copy()->addMinutes(5));
    $service->processServer($server->fresh());

    // Exactly one unresolved warning per threshold.
    expect(LowBalanceWarning::query()->where('server_id', $server->id)->unresolved()->count())->toBe(3);

    // Attempt a second identical insert for the same threshold — must be
    // silently ignored (inserted = 0), no exception, no transaction abort.
    $nowTs = now()->toDateTimeString();
    $dupInserted = DB::table('low_balance_warnings')->insertOrIgnore([
        'user_id' => $this->user->id,
        'server_id' => $server->id,
        'threshold_hours' => 24,
        'balance_toman' => 0,
        'rate_toman' => 850,
        'estimated_hours' => 0,
        'warned_at' => $nowTs,
        'created_at' => $nowTs,
        'updated_at' => $nowTs,
    ]);
    expect($dupInserted)->toBe(0);
    expect(LowBalanceWarning::query()->where('server_id', $server->id)->unresolved()->count())->toBe(3);

    // Resolve the 24h warning.
    LowBalanceWarning::query()
        ->where('server_id', $server->id)
        ->where('threshold_hours', 24)
        ->whereNull('resolved_at')
        ->update(['resolved_at' => now(), 'resolved_reason' => 'test_resolve']);

    expect(LowBalanceWarning::query()->where('server_id', $server->id)->unresolved()->count())->toBe(2);

    // Now the same threshold can be inserted again (resolved_at is not NULL
    // on the old row, so the partial unique index does not conflict).
    $reinserted = DB::table('low_balance_warnings')->insertOrIgnore([
        'user_id' => $this->user->id,
        'server_id' => $server->id,
        'threshold_hours' => 24,
        'balance_toman' => 0,
        'rate_toman' => 850,
        'estimated_hours' => 0,
        'warned_at' => $nowTs,
        'created_at' => $nowTs,
        'updated_at' => $nowTs,
    ]);
    expect($reinserted)->toBe(1);
    expect(LowBalanceWarning::query()->where('server_id', $server->id)->unresolved()->count())->toBe(3);
});
