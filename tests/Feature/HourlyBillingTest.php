<?php

use App\Enums\BillingMode;
use App\Enums\BillingState;
use App\Events\LowBalanceWarningTriggered;
use App\Exceptions\InvalidProductBillingException;
use App\Jobs\ProcessHourlyBillingJob;
use App\Models\LowBalanceWarning;
use App\Models\Product;
use App\Models\Server;
use App\Models\ServerBillingPeriod;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\HourlyBillingService;
use App\Services\OrderService;
use App\Services\PaymentService;
use App\Services\ServerActionService;
use App\Services\WalletService;
use Database\Seeders\FakeProviderSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;

beforeEach(function () {
    $this->seed(FakeProviderSeeder::class);
    $this->user = User::factory()->create();

    // Deterministic billing policy for the whole suite; individual tests
    // override these where the scenario under test requires it.
    config()->set('billing.hourly.minimum_prepaid_hours', 1);
    config()->set('billing.hourly.grace_hours', 48);
    config()->set('billing.hourly.lifecycle_action', 'notify_only');
});

afterEach(function () {
    Carbon::setTestNow();
});

function provisionHourlyServer(User $user, string $slug = 'vps-cx21-hourly'): Server
{
    $product = Product::query()->where('slug', $slug)->firstOrFail();

    $order = app(OrderService::class)->place($user, $product);
    $invoice = app(OrderService::class)->createInvoice($order, 'manual');
    $payment = app(PaymentService::class)->start($invoice, 'manual');
    app(PaymentService::class)->confirm($payment, ['approved' => true], $user);
    app(PaymentService::class)->provision($order->fresh());

    return $order->fresh()->server;
}

it('charges the hourly rate as the initial order total for hourly products', function () {
    $product = Product::query()->where('slug', 'vps-cx21-hourly')->firstOrFail();

    $order = app(OrderService::class)->place($this->user, $product);

    expect($order->billing_mode)->toBe(BillingMode::Hourly->value);
    expect($order->total_toman)->toBe(850);
    expect($order->items()->first()->unit_price_toman)->toBe(850);
    expect($order->cost_snapshot['hourly_price'])->toBe(850);
    expect($order->cost_snapshot['monthly_cap'])->toBeNull();
});

it('charges the hourly rate as the initial order total for capped products', function () {
    $product = Product::query()->where('slug', 'vps-cx21-capped')->firstOrFail();

    $order = app(OrderService::class)->place($this->user, $product);

    expect($order->billing_mode)->toBe(BillingMode::HourlyCapped->value);
    expect($order->total_toman)->toBe(850);
    expect($order->cost_snapshot['hourly_price'])->toBe(850);
    expect($order->cost_snapshot['monthly_cap'])->toBe(399000);
});

it('starts hourly billing at provisioning and funds the wallet from the payment', function () {
    $server = provisionHourlyServer($this->user);

    expect($server->billing_mode)->toBe(BillingMode::Hourly->value);
    expect($server->hourly_rate_toman)->toBe(850);
    expect($server->monthly_cap_toman)->toBeNull();
    expect($server->billing_started_at)->not->toBeNull();
    expect($server->last_billed_at)->toBeNull();
    expect($server->billing_stopped_at)->toBeNull();
    expect($server->expires_at)->toBeNull(); // hourly servers have no fixed expiry

    // The wallet relation may be cached as null on the order-time user
    // instance (fundingAmount reads it before the payment exists) — reload
    // fresh so the post-payment wallet row is observed.
    $wallet = $this->user->fresh()->wallet;
    expect($wallet)->not->toBeNull();
    expect($wallet->balance_toman)->toBe(850);
    expect($wallet->transactions()->where('type', WalletTransaction::TYPE_CREDIT)->count())->toBe(1);
});

it('charges a started hour in full using the ceil rounding policy', function () {
    $server = provisionHourlyServer($this->user);

    Carbon::setTestNow($server->billing_started_at->copy()->addMinutes(5));

    $recorded = app(HourlyBillingService::class)->processServer($server->fresh());

    expect($recorded)->toBe(1);

    $period = $server->fresh()->billingPeriods()->first();

    expect($period->amount_toman)->toBe(850);
    expect($period->rate_toman)->toBe(850);
    expect($period->currency)->toBe(ServerBillingPeriod::CURRENCY_IRR);
    expect($period->status)->toBe(ServerBillingPeriod::STATUS_PAID);
    expect($period->reference_type)->toBe(WalletTransaction::class);
    expect($period->reference_id)->not->toBeNull();

    $wallet = $this->user->fresh()->wallet;
    expect($wallet->balance_toman)->toBe(0);
    expect($wallet->transactions()->where('type', WalletTransaction::TYPE_DEBIT)->count())->toBe(1);
});

it('never charges the same billing interval twice', function () {
    $server = provisionHourlyServer($this->user);
    $service = app(HourlyBillingService::class);

    Carbon::setTestNow($server->billing_started_at->copy()->addMinutes(5));

    $service->processServer($server->fresh());
    $service->processServer($server->fresh());

    expect($server->fresh()->billingPeriods()->count())->toBe(1);
    expect($this->user->fresh()->wallet->balance_toman)->toBe(0);
});

it('keeps billing while the server is powered off', function () {
    $server = provisionHourlyServer($this->user);
    app(WalletService::class)->credit($this->user, 100000);

    // Power state changes are server actions, never billing start/stop.
    app(ServerActionService::class)->perform($server->fresh(), 'power_off', $this->user);

    Carbon::setTestNow($server->fresh()->billing_started_at->copy()->addHour()->addMinutes(10));

    app(HourlyBillingService::class)->processServer($server->fresh());

    $server = $server->fresh();

    expect($server->billing_stopped_at)->toBeNull();
    expect($server->power_state)->toBe('off');
    expect($server->billingPeriods()->count())->toBe(2);
    expect($this->user->wallet->fresh()->balance_toman)->toBe(100850 - 1700);
});

it('records an unpaid period instead of mutating the wallet when funds run out', function () {
    $server = provisionHourlyServer($this->user);

    Carbon::setTestNow($server->billing_started_at->copy()->addHour()->addMinutes(5));

    app(HourlyBillingService::class)->processServer($server->fresh());

    $periods = $server->fresh()->billingPeriods()->orderBy('id')->get();

    expect($periods)->toHaveCount(2);
    expect($periods[0]->status)->toBe(ServerBillingPeriod::STATUS_PAID);
    expect($periods[1]->status)->toBe(ServerBillingPeriod::STATUS_UNPAID);
    expect($periods[1]->amount_toman)->toBe(0);

    $wallet = $this->user->fresh()->wallet;
    expect($wallet->balance_toman)->toBe(0);
    expect($wallet->transactions()->where('type', WalletTransaction::TYPE_DEBIT)->count())->toBe(1);
});

it('charges the final partial hour (ceil) when the server is deleted', function () {
    $server = provisionHourlyServer($this->user);
    app(WalletService::class)->credit($this->user, 100000);

    Carbon::setTestNow($server->billing_started_at->copy()->addMinutes(25));

    app(ServerActionService::class)->perform($server->fresh(), 'delete', $this->user);

    $server = Server::query()->withTrashed()->find($server->id);

    expect($server->billing_stopped_at)->not->toBeNull();
    expect($server->status)->toBe(Server::STATUS_DELETED);
    expect($server->billingPeriods()->count())->toBe(1);
    expect($server->billingPeriods()->first()->amount_toman)->toBe(850);

    // The engine no longer charges a stopped server.
    app(HourlyBillingService::class)->processServer($server);
    expect(Server::query()->withTrashed()->find($server->id)->billingPeriods()->count())->toBe(1);
});

it('stops charging at the cap and resumes when the service cap period advances', function () {
    Carbon::setTestNow('2026-01-01 00:00:00');

    $server = provisionHourlyServer($this->user, 'vps-cx21-capped');

    expect($server->billing_mode)->toBe(BillingMode::HourlyCapped->value);
    expect($server->hourly_rate_toman)->toBe(850);
    expect($server->monthly_cap_toman)->toBe(399000);
    // Cap period is anchored to the service start (not the calendar month).
    expect($server->billing_period_started_at->toDateTimeString())->toBe('2026-01-01 00:00:00');
    expect($server->billing_period_ends_at->toDateTimeString())->toBe('2026-02-01 00:00:00');

    app(WalletService::class)->credit($this->user, 1000000);

    // Shrink the cap so the test stays fast and the math is easy to verify.
    $server->update(['monthly_cap_toman' => 4000]);

    // ~500 hours of accrued usage: 4 full hours + 1 partial capped hour.
    Carbon::setTestNow('2026-01-21 20:10:00');

    app(HourlyBillingService::class)->processServer($server->fresh());

    $server = $server->fresh();
    $periods = $server->billingPeriods()->get();

    expect($periods)->toHaveCount(5);
    expect((int) $periods->sum('amount_toman'))->toBe(4000);
    expect($periods->where('capped', true))->toHaveCount(1);
    expect($periods->where('capped', true)->first()->amount_toman)->toBe(600);
    expect($this->user->wallet->fresh()->balance_toman)->toBe(1000850 - 4000);
    expect($server->current_period_charged)->toBe(4000);

    // No further charges while the cap is still reached in this period.
    Carbon::setTestNow('2026-01-21 21:10:00');

    app(HourlyBillingService::class)->processServer($server);
    expect($server->fresh()->billingPeriods()->count())->toBe(5);

    // The next service cap period begins at 2026-02-01 00:00:00 (start + 1
    // month, no overflow) — which coincides with the calendar month only
    // because this service started on the 1st. Charging resumes for usage
    // that actually falls in the new period (late-January catch-up hours
    // stay capped).
    Carbon::setTestNow('2026-02-01 01:00:00');

    app(HourlyBillingService::class)->processServer($server);

    $server = $server->fresh();
    expect($server->billingPeriods()->count())->toBe(6);
    expect((int) $server->billingPeriods()->sum('amount_toman'))->toBe(4850);
    expect($server->billing_period_started_at->toDateTimeString())->toBe('2026-02-01 00:00:00');
    expect($server->billing_period_ends_at->toDateTimeString())->toBe('2026-03-01 00:00:00');

    $newPeriod = $server->billingPeriods()->where('period_start', '>=', '2026-02-01 00:00:00')->get();
    expect($newPeriod)->toHaveCount(1);
    expect($newPeriod->first()->amount_toman)->toBe(850);
    expect($this->user->wallet->fresh()->balance_toman)->toBe(1000850 - 4850);
});

it('does not reset the cap at a calendar-month boundary while the service period is open', function () {
    // Service starts near the end of August; its first cap period runs until
    // 2026-09-30 15:00:00 — crossing the September calendar boundary changes
    // nothing.
    Carbon::setTestNow('2026-08-31 15:00:00');

    $server = provisionHourlyServer($this->user, 'vps-cx21-capped');

    expect($server->billing_period_started_at->toDateTimeString())->toBe('2026-08-31 15:00:00');
    expect($server->billing_period_ends_at->toDateTimeString())->toBe('2026-09-30 15:00:00');

    app(WalletService::class)->credit($this->user, 1000000);
    $server->update(['monthly_cap_toman' => 4000]);

    // Reach the cap on the first day.
    Carbon::setTestNow('2026-08-31 20:00:00');
    app(HourlyBillingService::class)->processServer($server->fresh());

    expect((int) $server->fresh()->billingPeriods()->sum('amount_toman'))->toBe(4000);
    expect($server->fresh()->current_period_charged)->toBe(4000);

    // Calendar month changed to September, but the service cap period is
    // still open until 2026-09-30 15:00 — zero additional charges.
    Carbon::setTestNow('2026-09-01 12:00:00');
    app(HourlyBillingService::class)->processServer($server->fresh());

    $server = $server->fresh();
    expect($server->billingPeriods()->count())->toBe(5);
    expect((int) $server->billingPeriods()->sum('amount_toman'))->toBe(4000);

    // Crossing the actual service period boundary starts a fresh cap period.
    Carbon::setTestNow('2026-09-30 15:01:00');
    app(HourlyBillingService::class)->processServer($server);

    $server = $server->fresh();
    expect($server->billing_period_started_at->toDateTimeString())->toBe('2026-09-30 15:00:00');
    expect($server->billing_period_ends_at->toDateTimeString())->toBe('2026-10-30 15:00:00');
    expect($server->current_period_charged)->toBe(850);
    expect($server->billingPeriods()->count())->toBe(6);
    expect((int) $server->billingPeriods()->sum('amount_toman'))->toBe(4850);
});

it('anchors cap periods to the service start with no-overflow month arithmetic', function () {
    // A service starting on Jan 31 gets a 28-day first period (2026 is not a
    // leap year) — the period must end Feb 28, not overflow into March.
    Carbon::setTestNow('2026-01-31 15:00:00');

    $server = provisionHourlyServer($this->user, 'vps-cx21-capped');

    expect($server->billing_period_ends_at->toDateTimeString())->toBe('2026-02-28 15:00:00');

    app(WalletService::class)->credit($this->user, 1000000);
    $server->update(['monthly_cap_toman' => 4000]);

    Carbon::setTestNow('2026-01-31 20:00:00');
    app(HourlyBillingService::class)->processServer($server->fresh());

    expect($server->fresh()->current_period_charged)->toBe(4000);

    // The first unit that starts at/after Feb 28 15:00 belongs to the next
    // cap period and is charged in full.
    Carbon::setTestNow('2026-02-28 16:00:00');
    app(HourlyBillingService::class)->processServer($server->fresh());

    $server = $server->fresh();
    expect($server->billing_period_started_at->toDateTimeString())->toBe('2026-02-28 15:00:00');
    expect($server->billing_period_ends_at->toDateTimeString())->toBe('2026-03-28 15:00:00');
    expect($server->current_period_charged)->toBe(850);
    expect((int) $server->billingPeriods()->sum('amount_toman'))->toBe(4850);
});

it('does not run hourly billing for monthly servers and does not fund wallets', function () {
    $product = Product::query()->where('slug', 'vps-cx21')->firstOrFail();

    $order = app(OrderService::class)->place($this->user, $product);
    $invoice = app(OrderService::class)->createInvoice($order, 'manual');
    $payment = app(PaymentService::class)->start($invoice, 'manual');
    app(PaymentService::class)->confirm($payment, ['approved' => true], $this->user);
    app(PaymentService::class)->provision($order->fresh());

    $server = $order->fresh()->server;

    expect($server->billing_mode)->toBe(BillingMode::Monthly->value);
    expect($server->billing_started_at)->toBeNull();
    expect($server->hourly_rate_toman)->toBeNull();
    expect($this->user->wallet)->toBeNull();
});

it('processes due servers through the scheduled job', function () {
    $server = provisionHourlyServer($this->user);

    Carbon::setTestNow($server->billing_started_at->copy()->addMinutes(5));

    ProcessHourlyBillingJob::dispatchSync();

    expect($server->fresh()->billingPeriods()->count())->toBe(1);
});

it('processes hourly billing through the artisan command', function () {
    $server = provisionHourlyServer($this->user);

    Carbon::setTestNow($server->billing_started_at->copy()->addMinutes(5));

    $this->artisan('billing:process-hourly', ['--sync' => true])->assertSuccessful();

    expect($server->fresh()->billingPeriods()->count())->toBe(1);
    expect($this->user->fresh()->wallet->balance_toman)->toBe(0);
});

it('walks the insufficient-balance lifecycle to lifecycle action pending', function () {
    $server = provisionHourlyServer($this->user);
    $service = app(HourlyBillingService::class);
    $startedAt = $server->billing_started_at;

    // 1h05m of usage = 2 units (ceil); the first settles, the second fails.
    Carbon::setTestNow($startedAt->copy()->addHour()->addMinutes(5));
    $service->processServer($server->fresh());
    expect($server->fresh()->billing_state)->toBe(BillingState::LowBalance->value);

    Carbon::setTestNow($startedAt->copy()->addHours(2)->addMinutes(5));
    $service->processServer($server->fresh());
    expect($server->fresh()->billing_state)->toBe(BillingState::PaymentDue->value);

    Carbon::setTestNow($startedAt->copy()->addHours(3)->addMinutes(5));
    $service->processServer($server->fresh());
    $server = $server->fresh();
    expect($server->billing_state)->toBe(BillingState::Grace->value);
    expect($server->grace_started_at->toDateTimeString())->toBe($startedAt->copy()->addHours(3)->addMinutes(5)->toDateTimeString());
    expect($server->grace_ends_at->toDateTimeString())->toBe($startedAt->copy()->addHours(3)->addMinutes(5)->addHours(48)->toDateTimeString());

    // Repeated failures inside grace never rewrite the grace window.
    Carbon::setTestNow($startedAt->copy()->addHours(4)->addMinutes(5));
    $service->processServer($server->fresh());
    $server = $server->fresh();
    expect($server->billing_state)->toBe(BillingState::Grace->value);
    expect($server->grace_ends_at->toDateTimeString())->toBe($startedAt->copy()->addHours(3)->addMinutes(5)->addHours(48)->toDateTimeString());

    // Once grace elapses, the server moves to lifecycle_action_pending and
    // the (notify_only) lifecycle action is performed exactly once.
    Carbon::setTestNow($startedAt->copy()->addHours(3)->addMinutes(5)->addHours(49));
    $service->processServer($server->fresh());
    $server = $server->fresh();
    expect($server->billing_state)->toBe(BillingState::LifecycleActionPending->value);
    expect($server->lifecycle_action_performed_at)->not->toBeNull();

    // Idempotent — a later run never repeats the action.
    $performedAt = $server->lifecycle_action_performed_at;
    $service->processServer($server);
    expect(Server::query()->withTrashed()->find($server->id)->lifecycle_action_performed_at->equalTo($performedAt))->toBeTrue();
});

it('returns the server to active when the balance is replenished', function () {
    $server = provisionHourlyServer($this->user);
    $service = app(HourlyBillingService::class);
    $startedAt = $server->billing_started_at;

    Carbon::setTestNow($startedAt->copy()->addHour()->addMinutes(5));
    $service->processServer($server->fresh());
    expect($server->fresh()->billing_state)->toBe(BillingState::LowBalance->value);

    app(WalletService::class)->credit($this->user, 5000);

    Carbon::setTestNow($startedAt->copy()->addHours(2)->addMinutes(5));
    $service->processServer($server->fresh());

    $server = $server->fresh();
    expect($server->billing_state)->toBe(BillingState::Active->value);
    expect($server->grace_started_at)->toBeNull();
    expect($server->grace_ends_at)->toBeNull();
});

it('executes the configured power_off lifecycle action after grace expires', function () {
    config()->set('billing.hourly.lifecycle_action', 'power_off');

    $server = provisionHourlyServer($this->user);
    $service = app(HourlyBillingService::class);
    $startedAt = $server->billing_started_at;

    // Walk to grace: low_balance → payment_due → grace.
    Carbon::setTestNow($startedAt->copy()->addHour()->addMinutes(5));
    $service->processServer($server->fresh());
    Carbon::setTestNow($startedAt->copy()->addHours(2)->addMinutes(5));
    $service->processServer($server->fresh());
    Carbon::setTestNow($startedAt->copy()->addHours(3)->addMinutes(5));
    $service->processServer($server->fresh());
    expect($server->fresh()->billing_state)->toBe(BillingState::Grace->value);

    // Grace expires → the power_off lifecycle action runs at the provider.
    // Powering off does NOT stop customer hourly billing.
    Carbon::setTestNow($startedAt->copy()->addHours(3)->addMinutes(5)->addHours(49));
    $service->processServer($server->fresh());

    $server = $server->fresh();
    expect($server->billing_state)->toBe(BillingState::LifecycleActionPending->value);
    expect($server->power_state)->toBe('off');
    expect($server->status)->toBe(Server::STATUS_OFF);
    expect($server->lifecycle_action_performed_at)->not->toBeNull();
    expect($server->billing_stopped_at)->toBeNull();

    // Idempotent — never powered off twice.
    $performedAt = $server->lifecycle_action_performed_at;
    app(HourlyBillingService::class)->processServer($server);
    expect(Server::query()->withTrashed()->find($server->id)->lifecycle_action_performed_at->equalTo($performedAt))->toBeTrue();
});

it('terminates the server after grace when configured, settling the final hour', function () {
    config()->set('billing.hourly.lifecycle_action', 'terminate_after_grace');

    $server = provisionHourlyServer($this->user);
    $service = app(HourlyBillingService::class);
    $startedAt = $server->billing_started_at;

    Carbon::setTestNow($startedAt->copy()->addHour()->addMinutes(5));
    $service->processServer($server->fresh());
    Carbon::setTestNow($startedAt->copy()->addHours(2)->addMinutes(5));
    $service->processServer($server->fresh());
    Carbon::setTestNow($startedAt->copy()->addHours(3)->addMinutes(5));
    $service->processServer($server->fresh());
    expect($server->fresh()->billing_state)->toBe(BillingState::Grace->value);

    Carbon::setTestNow($startedAt->copy()->addHours(3)->addMinutes(5)->addHours(49));
    $service->processServer($server->fresh());

    $server = Server::query()->withTrashed()->find($server->id);
    expect($server->billing_state)->toBe(BillingState::LifecycleActionPending->value);
    expect($server->status)->toBe(Server::STATUS_DELETED);
    expect($server->trashed())->toBeTrue();
    expect($server->billing_stopped_at)->not->toBeNull();
    expect($server->lifecycle_action_performed_at)->not->toBeNull();

    // The final outstanding interval was settled before deletion. With the
    // ceil rounding policy the final billed unit covers the stop moment on
    // the unit grid (period_start ≤ stopped_at < period_end).
    expect($server->billingPeriods()->count())->toBeGreaterThan(0);
    $last = $server->billingPeriods()->latest('period_end')->first();
    $stoppedAt = Carbon::parse($server->billing_stopped_at);
    expect($last->period_start->lt($stoppedAt))->toBeTrue();
    expect($last->period_end->gte($stoppedAt))->toBeTrue();
});

it('creates deduplicated low-balance warnings at each configured threshold', function () {
    Event::fake([LowBalanceWarningTriggered::class]);

    $server = provisionHourlyServer($this->user);
    $service = app(HourlyBillingService::class);

    // 5 minutes in: the first unit is charged, the wallet empties, and every
    // threshold is breached.
    Carbon::setTestNow($server->billing_started_at->copy()->addMinutes(5));
    $service->processServer($server->fresh());

    $warnings = LowBalanceWarning::query()->where('server_id', $server->id)->orderByDesc('threshold_hours')->get();

    expect($warnings)->toHaveCount(3);
    expect($warnings->pluck('threshold_hours')->all())->toBe([24, 12, 6]);
    expect($warnings->every(fn (LowBalanceWarning $warning) => $warning->resolved_at === null))->toBeTrue();
    expect($warnings->first()->balance_toman)->toBe(0);
    expect($warnings->first()->rate_toman)->toBe(850);
    expect($warnings->first()->estimated_hours)->toBe(0);

    Event::assertDispatched(LowBalanceWarningTriggered::class, 3);

    // Idempotent — processing again creates nothing new.
    $service->processServer($server->fresh());
    expect(LowBalanceWarning::query()->where('server_id', $server->id)->count())->toBe(3);
});

it('resolves low-balance warnings on replenishment and re-triggers on a new dip', function () {
    $server = provisionHourlyServer($this->user);
    $service = app(HourlyBillingService::class);
    $startedAt = $server->billing_started_at;

    Carbon::setTestNow($startedAt->copy()->addMinutes(5));
    $service->processServer($server->fresh());
    expect(LowBalanceWarning::query()->where('server_id', $server->id)->unresolved()->count())->toBe(3);

    // Replenished → every pending warning is resolved with a reason.
    app(WalletService::class)->credit($this->user, 100000);

    Carbon::setTestNow($startedAt->copy()->addMinutes(10));
    $service->processServer($server->fresh());

    expect(LowBalanceWarning::query()->where('server_id', $server->id)->unresolved()->count())->toBe(0);
    expect(LowBalanceWarning::query()->where('server_id', $server->id)->where('resolved_reason', 'balance_replenished')->count())->toBe(3);

    // ~100 hours later only the 24h threshold is breached again (balance 15,850
    // < 24h × 850, but ≥ 12h × 850 and ≥ 6h × 850) → exactly one new warning.
    Carbon::setTestNow($startedAt->copy()->addHours(100));
    $service->processServer($server->fresh());

    $fresh = LowBalanceWarning::query()->where('server_id', $server->id)->unresolved()->get();
    expect($fresh)->toHaveCount(1);
    expect($fresh->first()->threshold_hours)->toBe(24);
    expect(LowBalanceWarning::query()->where('server_id', $server->id)->count())->toBe(4);
});

it('rejects ordering a product with an invalid billing configuration', function () {
    $product = Product::query()->where('slug', 'vps-cx21-hourly')->firstOrFail();
    $product->update(['hourly_price_toman' => null]);

    expect(fn () => app(OrderService::class)->place($this->user, $product->fresh()))
        ->toThrow(InvalidProductBillingException::class);
});
