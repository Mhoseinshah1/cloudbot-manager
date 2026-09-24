<?php

declare(strict_types=1);

namespace App\Subscriptions;

use App\Audit\AuditEvent;
use App\Audit\AuditRecorder;
use App\Cloud\Enums\ProviderCapability;
use App\Enums\ServerActionStatus;
use App\Enums\ServerActionType;
use App\Enums\ServerStatus;
use App\Enums\SettingKey;
use App\Enums\SubscriptionStatus;
use App\Models\Server;
use App\Models\ServerAction;
use App\Models\Subscription;
use App\Outbox\OutboxTopic;
use App\Outbox\OutboxWriter;
use App\Servers\ServerAccess;
use App\Settings\SettingsService;
use App\Subscriptions\Data\LifecycleReport;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Moves monthly subscriptions through the end of their life, and only that.
 *
 * Four things happen here and nothing else: a customer is warned that a period
 * is ending, an ended period opens a grace window, a machine is asked to power
 * off, and a grace window that closed asks for the machine to be deleted. No
 * money moves — renewal is something a customer chooses, never something a
 * sweep decides for them, and a system that silently charged a wallet the
 * moment a period lapsed would be taking money nobody agreed to spend.
 *
 * Two rules run through all of it.
 *
 * **Time comes from the period, not from the sweep.** Grace starts at
 * `current_period_end` and not at the moment this happened to run, so a worker
 * delayed by twenty minutes does not hand out twenty extra minutes of grace,
 * and a worker delayed by a day does not hand out a day. Every deterministic
 * key is built from the period end for the same reason: it is a fact about the
 * service, while "now" is a fact about the infrastructure.
 *
 * **Nothing destructive is decided from a stale read.** Every transition locks
 * the subscription and looks again before acting. The sweep and a customer's
 * renewal genuinely race at the boundary, and the one outcome that must be
 * impossible is deleting a machine somebody has just paid for.
 *
 * No provider call and no Telegram call happens here. Power-off and deletion
 * are recorded as server actions and carried out by the worker built for
 * waiting; messages go through the outbox and are delivered after commit.
 */
final readonly class MonthlyLifecycleService
{
    public function __construct(
        private SettingsService $settings,
        private ServerAccess $access,
        private AuditRecorder $audit,
        private OutboxWriter $outbox,
    ) {}

    /**
     * One bounded pass over the subscriptions that need attention.
     *
     * Chunked, and each subscription is its own short transaction: a sweep that
     * held one transaction over the whole estate would block every renewal
     * behind it and roll back everything it had done if one row went wrong.
     */
    public function sweep(int $batch = 200): LifecycleReport
    {
        $report = new LifecycleReport;

        $this->warnExpiring($report, $batch);
        $this->openGraceWindows($report, $batch);
        $this->closeGraceWindows($report, $batch);

        return $report;
    }

    /**
     * Tell customers whose period is ending soon.
     *
     * The sweep runs every few minutes and must not depend on firing at an
     * exact second, so the question asked is "is the end within this threshold"
     * rather than "is it exactly this many days away". The deterministic key
     * carries the period end and the threshold, so however many times that is
     * true, one warning is written.
     */
    private function warnExpiring(LifecycleReport $report, int $batch): void
    {
        $thresholds = $this->settings->dayThresholds(SettingKey::MonthlyExpiryWarningDays);

        if ($thresholds === null) {
            // No readable schedule is not an invitation to invent one. A
            // warning cadence nobody configured is a promise nobody made.
            return;
        }

        $now = CarbonImmutable::now();
        $furthest = $now->addDays(max($thresholds));

        Subscription::query()
            ->where('status', SubscriptionStatus::Active->value)
            ->where('current_period_end', '>', $now)
            ->where('current_period_end', '<=', $furthest)
            ->orderBy('id')
            ->limit($batch)
            ->get()
            ->each(function (Subscription $subscription) use ($thresholds, $now, $report): void {
                $server = Server::query()->whereKey($subscription->server_id)->first();

                if (! $server instanceof Server || $server->status === ServerStatus::Terminated) {
                    return;
                }

                $end = CarbonImmutable::instance($subscription->current_period_end);

                foreach ($thresholds as $days) {
                    if ($end->greaterThan($now->addDays($days))) {
                        // Not close enough for this threshold yet.
                        continue;
                    }

                    if ($this->warn($subscription, $server, $end, $days)) {
                        $report->warned++;
                    }

                    // The nearest threshold that applies is the one worth
                    // saying; the others are already recorded or will be as
                    // their own moment arrives.
                    break;
                }
            });
    }

    /**
     * Write one expiry warning, once.
     *
     * @return bool Whether this call was the one that wrote it.
     */
    private function warn(Subscription $subscription, Server $server, CarbonImmutable $end, int $days): bool
    {
        $key = self::warningKey((int) $subscription->getKey(), $end->getTimestamp(), $days);

        $message = $this->outbox->record(
            OutboxTopic::SubscriptionExpiryWarning,
            $subscription,
            [
                'subscription_id' => $subscription->getKey(),
                'server_id' => $server->getKey(),
                'server_name' => $server->name,
                'user_id' => $subscription->user_id,
                'expires_at' => $end->toIso8601String(),
                'days_left' => $days,
            ],
            $key,
        );

        // The writer returns the existing row for a key it has already seen, so
        // a repeated sweep produces no second message.
        return $message->wasRecentlyCreated;
    }

    /**
     * Open the grace window for periods that have ended.
     */
    private function openGraceWindows(LifecycleReport $report, int $batch): void
    {
        $now = CarbonImmutable::now();

        Subscription::query()
            ->where('status', SubscriptionStatus::Active->value)
            ->where('current_period_end', '<=', $now)
            ->orderBy('id')
            ->limit($batch)
            ->get()
            ->each(function (Subscription $subscription) use ($report): void {
                if ($this->enterGrace($subscription)) {
                    $report->graceEntered++;
                }
            });
    }

    /**
     * Move one expired subscription into grace, exactly once.
     *
     * @return bool Whether this call made the transition.
     */
    private function enterGrace(Subscription $subscription): bool
    {
        $graceHours = $this->settings->integer(SettingKey::MonthlyGraceHours);

        if ($graceHours === null || $graceHours < 0) {
            // Unreadable grace is not zero grace. Holding the subscription
            // where it is keeps the customer's machine alive until somebody
            // fixes the setting; guessing would start a countdown to deletion
            // from a number nobody chose.
            return false;
        }

        $powerOff = null;

        $entered = DB::transaction(function () use ($subscription, $graceHours, &$powerOff): bool {
            $locked = Subscription::query()->whereKey($subscription->getKey())->lockForUpdate()->first();

            if (! $locked instanceof Subscription) {
                return false;
            }

            // Looked at again under the lock. A renewal may have committed
            // between the batch being selected and this transaction opening,
            // and that renewal's period end is in the future.
            if ($locked->status !== SubscriptionStatus::Active) {
                return false;
            }

            $end = CarbonImmutable::instance($locked->current_period_end);

            if ($end->isFuture()) {
                // Renewed while we were looking. Nothing to do.
                return false;
            }

            // From the period end, never from now. A sweep that ran late must
            // not extend the customer's grace by however late it was.
            $graceUntil = $end->addHours($graceHours);

            $server = Server::query()->whereKey($locked->server_id)->first();

            if (! $server instanceof Server || $server->status === ServerStatus::Terminated) {
                // Nothing left to protect; the machine is already gone.
                return false;
            }

            $locked->forceFill([
                'status' => SubscriptionStatus::Grace->value,
                'grace_until' => $graceUntil,
            ])->save();

            $this->audit->record(
                AuditEvent::SubscriptionGraceEntered,
                subject: $locked,
                metadata: [
                    'subscription_id' => $locked->getKey(),
                    'server_id' => $server->getKey(),
                    'user_id' => $locked->user_id,
                    'period_end' => $end->toIso8601String(),
                    'grace_until' => $graceUntil->toIso8601String(),
                ],
            );

            $facts = [
                'subscription_id' => $locked->getKey(),
                'server_id' => $server->getKey(),
                'server_name' => $server->name,
                'user_id' => $locked->user_id,
                'expired_at' => $end->toIso8601String(),
                'grace_until' => $graceUntil->toIso8601String(),
            ];

            $this->outbox->record(
                OutboxTopic::SubscriptionGraceEntered,
                $locked,
                $facts,
                self::graceKey((int) $locked->getKey(), $end->getTimestamp()),
            );

            // The destructive warning is written here, at the start of grace,
            // rather than moments before deletion. It is the notice that
            // matters, and giving it with the full grace window still ahead is
            // the only version of it a customer can act on.
            $this->outbox->record(
                OutboxTopic::SubscriptionTerminationWarning,
                $locked,
                $facts,
                self::terminationWarningKey((int) $locked->getKey(), $end->getTimestamp()),
            );

            // Requested after the transaction commits: a provider call must
            // never happen inside one, and a power-off recorded against a
            // transaction that rolls back is an operation nobody asked for.
            $powerOff = ['server' => $server, 'period_end' => $end];

            return true;
        });

        if ($entered && is_array($powerOff)) {
            $this->requestPowerOff(
                $subscription,
                $powerOff['server'],
                $powerOff['period_end'],
            );
        }

        return $entered;
    }

    /**
     * Ask for the machine to be powered off, if the provider can do that.
     *
     * Operational only. It reduces what an unpaid machine is doing; it is not
     * proof that the provider has stopped charging us for it, and nothing in
     * the lifecycle treats it as such. A provider with no power control is not
     * a reason to hold up grace — the customer still has their window, and the
     * machine is simply left running until it is renewed or removed.
     */
    private function requestPowerOff(Subscription $subscription, Server $server, CarbonImmutable $periodEnd): void
    {
        if (! $this->access->supports($server, ServerActionType::PowerOff)) {
            return;
        }

        if (! in_array(ProviderCapability::PowerControl, $this->access->capabilities($server), strict: true)) {
            return;
        }

        $this->recordSystemAction(
            $server,
            ServerActionType::PowerOff,
            self::powerOffKey((int) $subscription->getKey(), $periodEnd->getTimestamp()),
            [
                'reason' => self::REASON_EXPIRED,
                'subscription_id' => (int) $subscription->getKey(),
                'period_end' => $periodEnd->toIso8601String(),
            ],
        );
    }

    /**
     * Act on grace windows that have closed.
     */
    private function closeGraceWindows(LifecycleReport $report, int $batch): void
    {
        $now = CarbonImmutable::now();

        Subscription::query()
            ->where('status', SubscriptionStatus::Grace->value)
            ->whereNotNull('grace_until')
            ->where('grace_until', '<=', $now)
            ->orderBy('id')
            ->limit($batch)
            ->get()
            ->each(function (Subscription $subscription) use ($report): void {
                if ($this->requestTermination($subscription)) {
                    $report->terminationRequested++;
                } else {
                    $report->graceHeld++;
                }
            });
    }

    /**
     * Ask for an expired machine to be deleted.
     *
     * The most dangerous thing this class does, so it is the most cautious. The
     * subscription is locked and re-read first: a renewal may have committed
     * since the batch was selected, and a sweep working from a stale row would
     * delete a server the customer has just paid for. The deletion's identity
     * carries the expired period, so a sweep still holding an old period end
     * cannot address a machine whose period has moved on.
     *
     * @return bool Whether a deletion was requested.
     */
    private function requestTermination(Subscription $subscription): bool
    {
        $enabled = $this->settings->boolean(SettingKey::AutoTerminateExpiredServers);

        if ($enabled !== true) {
            // Absent, unreadable or off. The subscription stays in grace and
            // the machine stays alive: not deleting costs money, and deleting
            // costs somebody their data.
            return false;
        }

        $request = null;

        DB::transaction(function () use ($subscription, &$request): void {
            $locked = Subscription::query()->whereKey($subscription->getKey())->lockForUpdate()->first();

            if (! $locked instanceof Subscription || $locked->status !== SubscriptionStatus::Grace) {
                // Renewed, cancelled or already finished while we were looking.
                return;
            }

            if ($locked->grace_until === null || CarbonImmutable::instance($locked->grace_until)->isFuture()) {
                // The window moved, or is not closed after all.
                return;
            }

            $end = CarbonImmutable::instance($locked->current_period_end);

            if ($end->isFuture()) {
                // The authoritative expiry is in the future: this is a renewed
                // subscription whose status has not been reconciled. Never
                // delete on the strength of a stale read.
                return;
            }

            $server = Server::query()->whereKey($locked->server_id)->first();

            if (! $server instanceof Server || $server->status === ServerStatus::Terminated) {
                return;
            }

            $warningKey = self::terminationWarningKey((int) $locked->getKey(), $end->getTimestamp());

            if (! $this->outbox->exists($warningKey)) {
                // A customer must have been told before their machine is
                // destroyed, and the durable record of having told them is the
                // only acceptable proof. Writing it now and deleting on the
                // next sweep gives them the notice they are owed.
                $this->outbox->record(
                    OutboxTopic::SubscriptionTerminationWarning,
                    $locked,
                    [
                        'subscription_id' => $locked->getKey(),
                        'server_id' => $server->getKey(),
                        'server_name' => $server->name,
                        'user_id' => $locked->user_id,
                        'expired_at' => $end->toIso8601String(),
                        'grace_until' => CarbonImmutable::instance($locked->grace_until)->toIso8601String(),
                    ],
                    $warningKey,
                );

                return;
            }

            $this->audit->record(
                AuditEvent::SubscriptionTerminationRequested,
                subject: $locked,
                metadata: [
                    'subscription_id' => $locked->getKey(),
                    'server_id' => $server->getKey(),
                    'user_id' => $locked->user_id,
                    'period_end' => $end->toIso8601String(),
                    'reason' => self::REASON_EXPIRED,
                ],
            );

            $request = ['server' => $server, 'period_end' => $end, 'subscription' => $locked];
        });

        if (! is_array($request)) {
            return false;
        }

        // Outside the transaction. The action row is the durable request; the
        // provider call belongs to the worker that executes it.
        $this->recordSystemAction(
            $request['server'],
            ServerActionType::Delete,
            self::terminationKey(
                (int) $request['subscription']->getKey(),
                $request['period_end']->getTimestamp(),
            ),
            [
                'reason' => self::REASON_EXPIRED,
                'subscription_id' => (int) $request['subscription']->getKey(),
                'period_end' => $request['period_end']->toIso8601String(),
            ],
        );

        return true;
    }

    /**
     * Record one action the system asked for, once.
     *
     * Written directly rather than through the customer-facing request path:
     * that one is scoped to an owner and refuses an inactive customer, which is
     * right for a button and wrong for a lifecycle whose whole job is acting on
     * services their owner has stopped paying for.
     *
     * @param  array<string, scalar|null>  $metadata
     */
    private function recordSystemAction(
        Server $server,
        ServerActionType $action,
        string $idempotencyKey,
        array $metadata,
    ): void {
        $existing = ServerAction::query()->where('idempotency_key', $idempotencyKey)->first();

        if ($existing instanceof ServerAction) {
            // Already asked for. A repeated sweep must not produce a second
            // delete, and the existing row is the one being worked on.
            return;
        }

        try {
            DB::transaction(function () use ($server, $action, $idempotencyKey, $metadata): void {
                $recorded = ServerAction::query()->create([
                    'server_id' => $server->getKey(),
                    'actor_type' => ServerAction::ACTOR_SYSTEM,
                    'actor_id' => null,
                    'action' => $action->value,
                    'status' => ServerActionStatus::Pending->value,
                    'idempotency_key' => $idempotencyKey,
                    'metadata' => $metadata,
                    'requested_at' => CarbonImmutable::now(),
                ]);

                $requestEvent = $action->requestAuditEvent();

                if ($requestEvent !== null) {
                    $this->audit->record(
                        $requestEvent,
                        subject: $recorded,
                        metadata: [
                            'server_action_id' => $recorded->getKey(),
                            'server_id' => $server->getKey(),
                            'user_id' => $server->user_id,
                            'action' => $action->value,
                            'reason' => $metadata['reason'] ?? null,
                        ],
                    );
                }

                // Inside the transaction, so the promise to perform it and the
                // record that it was asked for cannot come apart.
                $this->outbox->record(
                    OutboxTopic::ServerActionRequested,
                    $recorded,
                    [
                        'server_action_id' => $recorded->getKey(),
                        'server_id' => $server->getKey(),
                        'user_id' => $server->user_id,
                        'action' => $action->value,
                    ],
                    \App\Servers\ServerActionService::requestKey($recorded),
                );
            });
        } catch (QueryException) {
            // Two sweeps reached the unique key together. One row exists, which
            // is the outcome either way.
        }
    }

    /**
     * Ask for a renewed machine to be switched back on.
     *
     * Called after the renewal has committed, never inside it. A power-on that
     * fails does not undo the renewal: the customer has paid and owns the
     * period, and a machine that will not start is an operational problem for
     * the action reconciler and an operator, not a reason to take their money
     * back or shorten what they bought.
     */
    public function requestPowerOnAfterRenewal(Subscription $subscription): void
    {
        $server = Server::query()->whereKey($subscription->server_id)->first();

        if (! $server instanceof Server || $server->status === ServerStatus::Terminated) {
            return;
        }

        if ($server->power_state === \App\Enums\ServerPowerState::On) {
            // Local state already says it is running. Asking again would be a
            // provider call with nothing to do.
            return;
        }

        if (! $this->access->supports($server, ServerActionType::PowerOn)) {
            return;
        }

        $newEnd = CarbonImmutable::instance($subscription->current_period_end);

        $this->recordSystemAction(
            $server,
            ServerActionType::PowerOn,
            self::powerOnKey((int) $subscription->getKey(), $newEnd->getTimestamp()),
            [
                'reason' => self::REASON_RENEWED,
                'subscription_id' => (int) $subscription->getKey(),
                'period_end' => $newEnd->toIso8601String(),
            ],
        );
    }

    /**
     * Why a system action exists.
     *
     * Read by the termination finaliser to tell an expiry deletion from a
     * customer deleting their own server, because those end a subscription
     * differently and guessing from `actor_type` would lump every system delete
     * together.
     */
    public const REASON_EXPIRED = 'subscription_expired';

    public const REASON_RENEWED = 'subscription_renewed';

    public static function warningKey(int $subscriptionId, int $periodEndEpoch, int $days): string
    {
        return 'subscription:'.$subscriptionId.':period:'.$periodEndEpoch.':expiry-warning:'.$days;
    }

    public static function graceKey(int $subscriptionId, int $periodEndEpoch): string
    {
        return 'subscription:'.$subscriptionId.':period:'.$periodEndEpoch.':grace-entered';
    }

    public static function terminationWarningKey(int $subscriptionId, int $periodEndEpoch): string
    {
        return 'subscription:'.$subscriptionId.':period:'.$periodEndEpoch.':termination-warning';
    }

    public static function powerOffKey(int $subscriptionId, int $periodEndEpoch): string
    {
        return 'subscription:'.$subscriptionId.':period:'.$periodEndEpoch.':grace-poweroff';
    }

    public static function powerOnKey(int $subscriptionId, int $newPeriodEndEpoch): string
    {
        return 'subscription:'.$subscriptionId.':renewal:'.$newPeriodEndEpoch.':poweron';
    }

    /** The identity of one expiry deletion: this subscription, this expired period. */
    public static function terminationKey(int $subscriptionId, int $periodEndEpoch): string
    {
        return 'subscription:'.$subscriptionId.':period:'.$periodEndEpoch.':expiry-delete';
    }
}
