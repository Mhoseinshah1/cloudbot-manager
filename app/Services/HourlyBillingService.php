<?php

namespace App\Services;

use App\Enums\BillingMode;
use App\Enums\BillingState;
use App\Events\LowBalanceWarningTriggered;
use App\Exceptions\InsufficientWalletBalanceException;
use App\Models\LowBalanceWarning;
use App\Models\Server;
use App\Models\ServerBillingPeriod;
use App\Models\Setting;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * The hourly / hourly_capped billing engine.
 *
 * Semantics:
 * - Billing starts when a server is successfully provisioned (billing_started_at).
 * - Billing stops only when the server is permanently deleted (billing_stopped_at).
 * - power_on / power_off / reboot are server actions and never start or stop billing.
 * - Charges are settled from the customer wallet through WalletService only.
 * - Every interval is recorded in server_billing_periods; the
 *   (server_id, period_start, period_end) unique index prevents double charging.
 * - Rounding of partially-consumed hours follows the configured policy.
 * - hourly_capped periods are anchored to the service start, never calendar months.
 * - Minimum prepaid balance is enforced before provisioning.
 * - Insufficient-balance lifecycle: active → low_balance → payment_due →
 *   grace → lifecycle_action_pending.
 *
 * All money arithmetic uses integer toman.
 */
class HourlyBillingService
{
    public const ROUNDING_CEIL = 'ceil';

    public const ROUNDING_FLOOR = 'floor';

    public const ROUNDING_ROUND = 'round';

    public const LIFECYCLE_NOTIFY_ONLY = 'notify_only';

    public const LIFECYCLE_POWER_OFF = 'power_off';

    public const LIFECYCLE_TERMINATE = 'terminate_after_grace';

    public function __construct(
        private WalletService $wallets,
        private AuditService $audit,
    ) {}

    public function minimumPrepaidHours(): int
    {
        return max(0, (int) config('billing.hourly.minimum_prepaid_hours', 24));
    }

    public function graceHours(): int
    {
        return max(0, (int) config('billing.hourly.grace_hours', 48));
    }

    public function lifecycleAction(): string
    {
        $action = (string) config('billing.hourly.lifecycle_action', self::LIFECYCLE_NOTIFY_ONLY);

        return in_array($action, [self::LIFECYCLE_NOTIFY_ONLY, self::LIFECYCLE_POWER_OFF, self::LIFECYCLE_TERMINATE], true)
            ? $action
            : self::LIFECYCLE_NOTIFY_ONLY;
    }

    /**
     * @return array<int, int>
     */
    public function lowBalanceWarningHours(): array
    {
        $values = array_values(array_unique(array_map(
            'intval',
            (array) config('billing.hourly.low_balance_warning_hours', [24, 12, 6])
        )));

        rsort($values);

        return $values;
    }

    public function minimumRequiredBalance(int $hourlyRateToman): int
    {
        return $this->minimumPrepaidHours() * $hourlyRateToman;
    }

    public function fundingAmount(User $user, int $hourlyRateToman): int
    {
        $required = $this->minimumRequiredBalance($hourlyRateToman);
        $balance = (int) ($user->wallet->balance_toman ?? 0);
        $shortfall = max(0, $required - $balance);

        return max($hourlyRateToman, $shortfall);
    }

    public function assertMinimumPrepaid(User $user, int $hourlyRateToman): void
    {
        $required = $this->minimumRequiredBalance($hourlyRateToman);

        if ($required <= 0) {
            return;
        }

        $balance = (int) ($user->wallet->balance_toman ?? 0);

        if ($balance < $required) {
            throw InsufficientWalletBalanceException::forMinimumPrepaid(
                $user->id,
                $balance,
                $required,
                $this->minimumPrepaidHours(),
            );
        }
    }

    public function startBilling(Server $server, Subscription $subscription, ?Carbon $startedAt = null): void
    {
        if (! $server->isHourlyBilling()) {
            return;
        }

        $startedAt ??= now();

        $server->update([
            'billing_started_at' => $startedAt,
            'last_billed_at' => null,
            'billing_stopped_at' => null,
            'billing_period_started_at' => $startedAt,
            'billing_period_ends_at' => $this->capPeriodEnd($startedAt),
            'current_period_charged' => 0,
            'billing_state' => BillingState::Active->value,
            'billing_state_changed_at' => $startedAt,
            'grace_started_at' => null,
            'grace_ends_at' => null,
            'lifecycle_action_performed_at' => null,
        ]);

        $subscription->update([
            'current_period_start' => $startedAt,
        ]);

        $this->audit->record('billing.started', $server, after: [
            'billing_started_at' => $startedAt->toIso8601String(),
            'cap_period_started_at' => $startedAt->toIso8601String(),
            'cap_period_ends_at' => $server->billing_period_ends_at?->toIso8601String(),
            'hourly_rate_toman' => $server->hourly_rate_toman,
            'monthly_cap_toman' => $server->monthly_cap_toman,
            'minimum_prepaid_hours' => $this->minimumPrepaidHours(),
        ]);
    }

    public function stopBilling(Server $server, ?Carbon $stoppedAt = null): void
    {
        if (! $server->isHourlyBilling() || $server->billing_started_at === null) {
            return;
        }

        $stoppedAt ??= now();

        $lock = Cache::lock('hourly-billing:server:'.$server->id, 30);
        $acquired = $lock->get();

        try {
            DB::transaction(function () use ($server, $stoppedAt) {
                /** @var Server|null $locked */
                $locked = Server::query()->withTrashed()->lockForUpdate()->find($server->id);

                if ($locked === null || $locked->billing_stopped_at !== null) {
                    return;
                }

                $this->chargeDueUnits($locked, $stoppedAt);
                $locked->update(['billing_stopped_at' => $stoppedAt]);

                $this->audit->record('billing.stopped', $locked, after: [
                    'billing_stopped_at' => $stoppedAt->toIso8601String(),
                    'final_period_end' => $locked->last_billed_at?->toIso8601String(),
                ]);
            });
        } finally {
            if ($acquired) {
                $lock->release();
            }
        }
    }

    public function processDueServers(?Carbon $now = null): int
    {
        $now ??= now();
        $recorded = 0;

        Server::query()
            ->withTrashed()
            ->whereNotNull('billing_started_at')
            ->whereIn('billing_mode', [BillingMode::Hourly->value, BillingMode::HourlyCapped->value])
            ->where(function (Builder $query) {
                $query->whereNull('billing_stopped_at')
                    ->orWhere(function (Builder $stopped) {
                        $stopped->whereNotNull('billing_stopped_at')
                            ->where(function (Builder $pending) {
                                $pending->whereNull('last_billed_at')
                                    ->orWhereColumn('last_billed_at', '<', 'billing_stopped_at');
                            });
                    });
            })
            ->chunkById(100, function ($servers) use (&$recorded, $now) {
                foreach ($servers as $server) {
                    $recorded += $this->processServer($server, $now);
                }
            });

        return $recorded;
    }

    public function processServer(Server $server, ?Carbon $now = null): int
    {
        if (! $server->isHourlyBilling() || $server->billing_started_at === null) {
            return 0;
        }

        $now ??= now();
        $lock = Cache::lock('hourly-billing:server:'.$server->id, 30);

        if (! $lock->get()) {
            return 0;
        }

        try {
            $recorded = DB::transaction(function () use ($server, $now) {
                /** @var Server|null $locked */
                $locked = Server::query()->withTrashed()->lockForUpdate()->find($server->id);

                if ($locked === null) {
                    return 0;
                }

                $recorded = $this->chargeDueUnits($locked, $now);
                $this->handleGraceExpiry($locked, $now);
                $this->evaluateLowBalanceWarnings($locked, $now);

                return $recorded;
            });
        } finally {
            $lock->release();
        }

        $this->executeLifecycleAction(Server::query()->withTrashed()->find($server->id), $now);

        return $recorded;
    }

    public function roundingPolicy(): string
    {
        try {
            $policy = Setting::get('billing.hourly_rounding', self::ROUNDING_CEIL);

            return in_array($policy, [self::ROUNDING_CEIL, self::ROUNDING_FLOOR, self::ROUNDING_ROUND], true)
                ? $policy
                : self::ROUNDING_CEIL;
        } catch (\Throwable) {
            return self::ROUNDING_CEIL;
        }
    }

    public function chargeableUntil(Server $server, Carbon $boundary): Carbon
    {
        $start = Carbon::parse($server->billing_started_at);

        if ($boundary->lte($start)) {
            return $start->copy();
        }

        $elapsedMinutes = (int) $start->diffInMinutes($boundary);

        $units = match ($this->roundingPolicy()) {
            self::ROUNDING_FLOOR => intdiv($elapsedMinutes, 60),
            self::ROUNDING_ROUND => intdiv($elapsedMinutes + 30, 60),
            default => intdiv($elapsedMinutes + 59, 60),
        };

        return $start->copy()->addMinutes($units * 60);
    }

    private function chargeDueUnits(Server $server, Carbon $now): int
    {
        $cursor = Carbon::parse($server->last_billed_at ?? $server->billing_started_at);
        $boundary = $server->billing_stopped_at !== null
            ? Carbon::parse($server->billing_stopped_at)
            : $now;
        $until = $this->chargeableUntil($server, $boundary);

        if (! $until->greaterThan($cursor)) {
            return 0;
        }

        $rate = (int) ($server->hourly_rate_toman ?? 0);

        if ($rate <= 0) {
            return 0;
        }

        $recorded = 0;
        $periodStart = $cursor->copy();

        while ($periodStart->lt($until)) {
            $periodEnd = $periodStart->copy()->addHour();

            if ($periodEnd->greaterThan($until)) {
                $periodEnd = $until->copy();
            }

            if ($this->chargeUnit($server, $periodStart, $periodEnd, $rate)) {
                $recorded++;
            }

            $periodStart = $periodEnd;
        }

        $server->update(['last_billed_at' => $until]);

        return $recorded;
    }

    private function chargeUnit(Server $server, Carbon $periodStart, Carbon $periodEnd, int $rate): bool
    {
        $amount = $rate;
        $capped = false;

        if ($server->isHourlyCappedBilling()) {
            [$amount, $capped] = $this->applyCapPeriod($server, $periodStart, $amount);

            if ($amount <= 0) {
                return false;
            }
        }

        $owner = $server->user;

        if (! $owner instanceof User) {
            return false;
        }

        $period = $this->recordPeriod(
            $server,
            $periodStart,
            $periodEnd,
            $rate,
            $amount,
            ServerBillingPeriod::STATUS_UNPAID,
            $capped,
        );

        if ($period === null) {
            return false;
        }

        $description = 'Hourly VPS usage — '.$server->name.' ('.$periodStart->format('Y-m-d H:i').' – '.$periodEnd->format('Y-m-d H:i').')';

        try {
            $transaction = $this->wallets->debit(
                $owner,
                $amount,
                description: $description,
                reference: $period,
            );
        } catch (InsufficientWalletBalanceException) {
            $period->update([
                'status' => ServerBillingPeriod::STATUS_UNPAID,
                'amount_toman' => 0,
                'description' => 'Insufficient wallet balance',
            ]);

            $this->audit->record('billing.hourly.unpaid', $server, after: [
                'period_start' => $periodStart->toIso8601String(),
                'period_end' => $periodEnd->toIso8601String(),
                'rate_toman' => $rate,
                'billing_period_id' => $period->id,
            ]);

            $this->handleFailedCharge($server);

            return true;
        }

        $period->update([
            'status' => ServerBillingPeriod::STATUS_PAID,
            'reference_type' => $transaction->getMorphClass(),
            'reference_id' => $transaction->getKey(),
        ]);

        if ($server->isHourlyCappedBilling()) {
            $server->increment('current_period_charged', $amount);
        }

        $this->audit->record('billing.hourly.charged', $server, after: [
            'period_start' => $periodStart->toIso8601String(),
            'period_end' => $periodEnd->toIso8601String(),
            'rate_toman' => $rate,
            'amount_toman' => $amount,
            'capped' => $capped,
            'billing_period_id' => $period->id,
            'wallet_transaction_id' => $transaction->id,
        ]);

        $this->recoverFromUnpaidState($server);

        return true;
    }

    /**
     * @return array{0: int, 1: bool}
     */
    private function applyCapPeriod(Server $server, Carbon $unitStart, int $amount): array
    {
        $cap = (int) ($server->monthly_cap_toman ?? 0);

        if ($cap <= 0) {
            return [$amount, false];
        }

        $this->advanceCapPeriodIfNeeded($server, $unitStart);

        $used = (int) $server->current_period_charged;

        if ($used >= $cap) {
            return [0, true];
        }

        if ($used + $amount > $cap) {
            return [$cap - $used, true];
        }

        return [$amount, false];
    }

    private function advanceCapPeriodIfNeeded(Server $server, Carbon $unitStart): void
    {
        $periodStart = $server->billing_period_started_at !== null
            ? Carbon::parse($server->billing_period_started_at)
            : Carbon::parse($server->billing_started_at);
        $periodEnd = $server->billing_period_ends_at !== null
            ? Carbon::parse($server->billing_period_ends_at)
            : $this->capPeriodEnd($periodStart);

        if ($unitStart->lt($periodEnd)) {
            return;
        }

        while (! $unitStart->lt($periodEnd)) {
            $periodStart = $periodEnd->copy();
            $periodEnd = $this->capPeriodEnd($periodStart);
        }

        $server->update([
            'billing_period_started_at' => $periodStart,
            'billing_period_ends_at' => $periodEnd,
            'current_period_charged' => 0,
        ]);

        $this->audit->record('billing.cap_period.advanced', $server, after: [
            'period_started_at' => $periodStart->toIso8601String(),
            'period_ends_at' => $periodEnd->toIso8601String(),
        ]);
    }

    private function capPeriodEnd(Carbon $periodStart): Carbon
    {
        return $periodStart->copy()->addMonthNoOverflow();
    }

    private function handleFailedCharge(Server $server): void
    {
        $state = BillingState::tryFrom((string) $server->billing_state) ?? BillingState::Active;

        $next = match ($state) {
            BillingState::Active => BillingState::LowBalance,
            BillingState::LowBalance => BillingState::PaymentDue,
            BillingState::PaymentDue => BillingState::Grace,
            default => null,
        };

        if ($next === null) {
            return;
        }

        $now = now();

        if ($next === BillingState::Grace) {
            $graceEnd = $now->copy()->addHours($this->graceHours());

            $server->update([
                'billing_state' => $next->value,
                'billing_state_changed_at' => $now,
                'grace_started_at' => $now,
                'grace_ends_at' => $graceEnd,
            ]);
        } else {
            $server->update([
                'billing_state' => $next->value,
                'billing_state_changed_at' => $now,
            ]);
        }

        $this->audit->record('billing.state.changed', $server, before: [
            'billing_state' => $state->value,
        ], after: [
            'billing_state' => $server->billing_state,
            'billing_state_changed_at' => $now->toIso8601String(),
            'grace_started_at' => $server->grace_started_at?->toIso8601String(),
            'grace_ends_at' => $server->grace_ends_at?->toIso8601String(),
        ]);
    }

    private function recoverFromUnpaidState(Server $server): void
    {
        $state = BillingState::tryFrom((string) $server->billing_state) ?? BillingState::Active;

        if ($state === BillingState::Active) {
            return;
        }

        $now = now();

        $server->update([
            'billing_state' => BillingState::Active->value,
            'billing_state_changed_at' => $now,
            'grace_started_at' => null,
            'grace_ends_at' => null,
        ]);

        $this->audit->record('billing.state.changed', $server, before: [
            'billing_state' => $state->value,
        ], after: [
            'billing_state' => BillingState::Active->value,
            'billing_state_changed_at' => $now->toIso8601String(),
        ]);
    }

    private function handleGraceExpiry(Server $server, Carbon $now): void
    {
        if ($server->billing_state !== BillingState::Grace->value) {
            return;
        }

        if ($server->grace_ends_at === null || $now->lt(Carbon::parse($server->grace_ends_at))) {
            return;
        }

        $this->transitionTo($server, BillingState::LifecycleActionPending, $now);
    }

    private function transitionTo(Server $server, BillingState $to, Carbon $now): void
    {
        $from = BillingState::tryFrom((string) $server->billing_state) ?? BillingState::Active;

        if ($from === $to) {
            return;
        }

        $server->update([
            'billing_state' => $to->value,
            'billing_state_changed_at' => $now,
        ]);

        $this->audit->record('billing.state.changed', $server, before: [
            'billing_state' => $from->value,
        ], after: [
            'billing_state' => $to->value,
            'billing_state_changed_at' => $now->toIso8601String(),
        ]);
    }

    private function executeLifecycleAction(?Server $server, Carbon $now): void
    {
        if ($server === null) {
            return;
        }

        if ($server->billing_state !== BillingState::LifecycleActionPending->value) {
            return;
        }

        if ($server->lifecycle_action_performed_at !== null) {
            return;
        }

        $action = $this->lifecycleAction();
        $serverActions = app(ServerActionService::class);

        if ($action === self::LIFECYCLE_POWER_OFF) {
            $serverActions->perform(
                $server,
                'power_off',
                null,
                null,
                ServerActionService::SYSTEM_CONTEXT_BILLING_LIFECYCLE,
            );
        } elseif ($action === self::LIFECYCLE_TERMINATE) {
            $serverActions->perform(
                $server,
                'delete',
                null,
                null,
                ServerActionService::SYSTEM_CONTEXT_BILLING_LIFECYCLE,
            );
        }

        Server::query()->withTrashed()->whereKey($server->id)->update(['lifecycle_action_performed_at' => $now]);

        $this->audit->record('billing.lifecycle_action.executed', $server, after: [
            'action' => $action,
            'performed_at' => $now->toIso8601String(),
        ]);
    }

    /**
     * @return array<int, LowBalanceWarning>
     */
    public function evaluateLowBalanceWarnings(Server $server, ?Carbon $now = null): array
    {
        if (! $server->isHourlyBilling()) {
            return [];
        }

        $now ??= now();
        $rate = (int) ($server->hourly_rate_toman ?? 0);

        if ($rate <= 0) {
            return [];
        }

        $balance = (int) (Wallet::query()->where('user_id', $server->user_id)->value('balance_toman') ?? 0);
        $created = [];

        foreach ($this->lowBalanceWarningHours() as $thresholdHours) {
            $required = $thresholdHours * $rate;

            /** @var LowBalanceWarning|null $pending */
            $pending = LowBalanceWarning::query()
                ->where('server_id', $server->id)
                ->where('threshold_hours', $thresholdHours)
                ->whereNull('resolved_at')
                ->first();

            if ($balance < $required) {
                if ($pending === null) {
                    $timestamp = now();
                    $inserted = DB::table('low_balance_warnings')->insertOrIgnore([
                        'user_id' => $server->user_id,
                        'server_id' => $server->id,
                        'threshold_hours' => $thresholdHours,
                        'balance_toman' => $balance,
                        'rate_toman' => $rate,
                        'estimated_hours' => intdiv($balance, $rate),
                        'warned_at' => $now,
                        'resolved_at' => null,
                        'resolved_reason' => null,
                        'created_at' => $timestamp,
                        'updated_at' => $timestamp,
                    ]);

                    if ($inserted === 1) {
                        $warning = LowBalanceWarning::query()
                            ->where('server_id', $server->id)
                            ->where('threshold_hours', $thresholdHours)
                            ->whereNull('resolved_at')
                            ->first();

                        if ($warning !== null) {
                            LowBalanceWarningTriggered::dispatch($warning);
                            $created[] = $warning;
                        }
                    }
                }

                continue;
            }

            if ($pending !== null) {
                $pending->update([
                    'resolved_at' => $now,
                    'resolved_reason' => 'balance_replenished',
                ]);
            }
        }

        return $created;
    }

    private function recordPeriod(
        Server $server,
        Carbon $periodStart,
        Carbon $periodEnd,
        int $rate,
        int $amount,
        string $status,
        bool $capped,
        ?string $description = null,
    ): ?ServerBillingPeriod {
        $timestamp = now();

        $inserted = DB::table('server_billing_periods')->insertOrIgnore([
            'server_id' => $server->id,
            'subscription_id' => $server->subscription?->id,
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
            'rate_toman' => $rate,
            'amount_toman' => $amount,
            'currency' => ServerBillingPeriod::CURRENCY_IRR,
            'status' => $status,
            'capped' => $capped,
            'description' => $description,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        if ($inserted !== 1) {
            return null;
        }

        return ServerBillingPeriod::query()
            ->where('server_id', $server->id)
            ->where('period_start', $periodStart)
            ->where('period_end', $periodEnd)
            ->first();
    }
}
