<?php

declare(strict_types=1);

namespace App\Servers;

use App\Audit\AuditEvent;
use App\Audit\AuditRecorder;
use App\Enums\ServerActionStatus;
use App\Enums\ServerPowerState;
use App\Enums\ServerStatus;
use App\Enums\SubscriptionStatus;
use App\Models\Server;
use App\Models\ServerAction;
use App\Models\Subscription;
use App\Models\User;
use App\Outbox\OutboxTopic;
use App\Outbox\OutboxWriter;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Ends a server's life locally, once the provider has confirmed it is gone.
 *
 * Only ever called after a confirmed remote deletion. A local termination
 * written on a hopeful assumption is the worst of both worlds: the customer
 * loses their service and the provider keeps charging for a machine nobody is
 * watching.
 *
 * No money moves here. A customer who deletes a monthly server early gets no
 * prorated refund in Release 1.0 — the policy is deliberate, and RefundService
 * is not called, not conditionally and not at all. What they get is their
 * service ending immediately, which is what deleting a machine means.
 *
 * Locks in the project's order — user, then subscription, then server — so this
 * and a concurrent wallet movement queue behind the same lock rather than
 * deadlocking against each other.
 */
final readonly class ServerTerminationService
{
    public function __construct(
        private OutboxWriter $outbox,
        private AuditRecorder $audit,
    ) {}

    /**
     * Finish a confirmed deletion, exactly once.
     *
     * Idempotent by re-reading under the locks: a server already terminated is
     * left exactly as it was, because a second pass must not restate when
     * somebody's service ended or send them a second farewell.
     *
     * @return bool Whether this call was the one that terminated it.
     */
    public function finalize(ServerAction $action): bool
    {
        return DB::transaction(function () use ($action): bool {
            $current = ServerAction::query()->whereKey($action->getKey())->lockForUpdate()->first();

            if (! $current instanceof ServerAction) {
                return false;
            }

            $server = Server::query()->whereKey($current->server_id)->first();

            if (! $server instanceof Server) {
                return false;
            }

            // User, then subscription, then server.
            User::query()->whereKey($server->user_id)->lockForUpdate()->first();

            $subscription = Subscription::query()
                ->where('server_id', $server->getKey())
                ->lockForUpdate()
                ->first();

            $locked = Server::query()->whereKey($server->getKey())->lockForUpdate()->first();

            if (! $locked instanceof Server) {
                return false;
            }

            if ($locked->status === ServerStatus::Terminated) {
                // Somebody already finished this. Settle the action if it is
                // still open — a second worker arriving late should not leave
                // a delete looking unfinished — but change nothing else.
                $this->settleQuietly($current);

                return false;
            }

            $endedAt = CarbonImmutable::now();

            $locked->forceFill([
                'status' => ServerStatus::Terminated->value,
                'power_state' => ServerPowerState::Off->value,
                'terminated_at' => $endedAt,
            ])->save();

            if ($subscription instanceof Subscription) {
                $this->endEntitlement($subscription, $endedAt);
            }

            $this->settleQuietly($current);

            $this->audit->record(
                AuditEvent::ServerTerminated,
                subject: $locked,
                after: ['status' => ServerStatus::Terminated->value],
                metadata: [
                    'server_id' => $locked->getKey(),
                    'user_id' => $locked->user_id,
                    'order_id' => $locked->order_id,
                    'server_action_id' => $current->getKey(),
                    'provider_server_id' => $locked->provider_server_id,
                    // Stated plainly, because the absence of a refund is a
                    // policy decision and an investigation should find it
                    // recorded rather than inferred from nothing being there.
                    'refunded' => false,
                ],
            );

            // Inside the transaction: a customer told their server is gone by
            // a transaction that then rolls back has been told something the
            // system cannot take back.
            $this->outbox->record(
                OutboxTopic::ServerTerminated,
                $locked,
                [
                    'server_id' => $locked->getKey(),
                    'server_name' => $locked->name,
                    'user_id' => $locked->user_id,
                    'order_id' => $locked->order_id,
                    'terminated_at' => $endedAt->toIso8601String(),
                ],
                self::terminationKey($locked),
            );

            return true;
        });
    }

    /** One farewell per server, however many workers reach the end. */
    public static function terminationKey(Server $server): string
    {
        return 'server:'.$server->getKey().':terminated';
    }

    /**
     * Stop the customer's entitlement at the moment their machine went.
     *
     * `current_period_end` is the authoritative expiry, so leaving it in the
     * future would say a customer is owed service on a server that no longer
     * exists — which is what a renewal sweep would later read. It is only ever
     * brought forward: a deletion cannot extend anybody's period, and a period
     * that already ended is not reopened.
     */
    private function endEntitlement(Subscription $subscription, CarbonImmutable $endedAt): void
    {
        $attributes = [
            'status' => SubscriptionStatus::Cancelled->value,
            'cancelled_at' => $endedAt,
        ];

        if ($subscription->current_period_end->greaterThan($endedAt)) {
            // Never before the period began. A customer who deletes a machine
            // in the same second it arrived has had no service, and the period
            // collapses to its start rather than inverting.
            $attributes['current_period_end'] = $endedAt->lessThan($subscription->current_period_start)
                ? CarbonImmutable::instance($subscription->current_period_start)
                : $endedAt;
        }

        $subscription->forceFill($attributes)->save();
    }

    private function settleQuietly(ServerAction $action): void
    {
        if (! $action->isOpen()) {
            return;
        }

        ServerAction::query()
            ->whereKey($action->getKey())
            ->whereIn('status', [ServerActionStatus::Pending->value, ServerActionStatus::Running->value])
            ->update([
                'status' => ServerActionStatus::Succeeded->value,
                'settled_at' => CarbonImmutable::now(),
                'error_category' => null,
                'updated_at' => now(),
            ]);
    }
}
