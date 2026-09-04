<?php

declare(strict_types=1);

namespace App\Provisioning;

use App\Audit\AuditEvent;
use App\Audit\AuditRecorder;
use App\Cloud\Contracts\CloudProviderInterface;
use App\Cloud\Enums\ProviderServerStatus;
use App\Cloud\Exceptions\ProviderException;
use App\Cloud\ProviderManager;
use App\Enums\ConfirmedNoServerOutcome;
use App\Enums\OrderStatus;
use App\Enums\ProvisioningOutcome;
use App\Enums\ProvisioningStage;
use App\Enums\SettingKey;
use App\Models\Order;
use App\Orders\RefundService;
use App\Provisioning\Data\ProvisioningResult;
use App\Provisioning\Exceptions\RemoteIdentityConflict;
use App\Provisioning\Exceptions\RemoteIdentityMismatch;
use App\Settings\SettingsService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Finds out what actually happened to orders nobody finished.
 *
 * The safety net under every gap the architecture cannot close: a worker that
 * died between the provider answering and the local write, a queue retry that
 * was lost, a create whose response never arrived. In all of those the durable
 * provisioning token is the only thing left, and it is enough — the token says
 * which machine was intended, and the provider can be asked whether it exists.
 *
 * Everything here starts from that token and from nothing else. It never infers
 * a machine's absence from a failed lookup, never picks between two candidates,
 * and never asks a provider to create anything.
 *
 * It reads through `driver()` rather than `for()`, deliberately. A disabled
 * provider must not be sold from, but a paid customer whose server may already
 * exist still needs somebody to look — and looking spends nothing.
 */
final readonly class ReconciliationService
{
    public function __construct(
        private ProviderManager $providers,
        private SettingsService $settings,
        private OrderPlanner $planner,
        private TokenLookup $tokens,
        private AttemptRecorder $attempts,
        private ServerPersister $persister,
        private RefundService $refunds,
        private OperationalAlerts $alerts,
        private AuditRecorder $audit,
    ) {}

    /**
     * Resolve one order against what its provider actually holds.
     */
    public function reconcile(Order $order): ProvisioningResult
    {
        if ($order->status === OrderStatus::Provisioned) {
            $server = $order->server;

            return $server === null
                ? ProvisioningResult::notEligible($order, 'That order is already provisioned.')
                : ProvisioningResult::provisioned($order, $server, ProvisioningOutcome::RecoveredExisting);
        }

        if (! in_array($order->status, [OrderStatus::Provisioning, OrderStatus::NeedsAttention], strict: true)) {
            return ProvisioningResult::notEligible(
                $order, 'Only an order with a provider request behind it can be reconciled.',
            );
        }

        if ($order->provisioning_uuid === null) {
            // Nothing was ever committed to create with, so nothing can exist
            // because of this order.
            return ProvisioningResult::notEligible($order, 'That order has no provisioning token.');
        }

        $plan = $this->planner->plan($order);
        $provider = $this->readableProvider($plan->providerCode);

        if (! $provider instanceof CloudProviderInterface) {
            return ProvisioningResult::retryable(
                $order, ProvisioningOutcome::TransientFailure, 'That provider cannot be read right now.',
            );
        }

        try {
            $matches = $this->tokens->find($provider, (string) $order->provisioning_uuid);
        } catch (ProviderException $exception) {
            // A provider we cannot read is not a provider holding nothing. This
            // is the single most dangerous inference available here, and it is
            // not made.
            return ProvisioningResult::retryable(
                $order,
                ProvisioningOutcome::TransientFailure,
                'The provider could not be read: '.$exception->getMessage(),
            );
        }

        $this->audit->record(
            AuditEvent::ProvisioningReconciled,
            subject: $order,
            metadata: [
                'order_id' => $order->getKey(),
                'provisioning_uuid' => $order->provisioning_uuid,
                'match_count' => $matches->count(),
                'tombstoned' => $matches->isTombstoned(),
            ],
        );

        if ($matches->isAmbiguous()) {
            // Never resolved automatically. Choosing would hand a customer one
            // of two machines and leave the other billing quietly.
            return $this->park(
                $order,
                ProvisioningOutcome::AmbiguousRemoteMatch,
                'ambiguous_remote_match',
                'More than one remote server carries this order\'s provisioning token.',
                [
                    'match_count' => $matches->count(),
                    'provider_server_ids' => implode(',', $matches->providerServerIds()),
                ],
            );
        }

        if ($matches->isUnique()) {
            $remote = $matches->sole();

            if ($remote !== null && $remote->status !== ProviderServerStatus::Active) {
                // It exists but is not usable yet. Leave the order where it is;
                // creating anything now would make a second machine.
                return ProvisioningResult::remotePending(
                    $order, 'The provider is still building this server.',
                );
            }

            $attempt = $this->attempts->open($order, ProvisioningStage::Persist, $plan);

            try {
                $server = $this->persister->persist($order, $remote, $plan);
            } catch (RemoteIdentityMismatch|RemoteIdentityConflict $exception) {
                $this->attempts->close(
                    $attempt, ProvisioningStage::Persist,
                    ProvisioningOutcome::IdentityMismatch, server: $remote,
                );

                return $this->park(
                    $order,
                    ProvisioningOutcome::IdentityMismatch,
                    'remote_identity_mismatch',
                    $exception->getMessage(),
                );
            } catch (Throwable $exception) {
                $this->attempts->close(
                    $attempt, ProvisioningStage::Persist,
                    ProvisioningOutcome::RemoteCreatedLocalFailed, server: $remote,
                );

                return ProvisioningResult::retryable(
                    $order,
                    ProvisioningOutcome::RemoteCreatedLocalFailed,
                    'The server exists remotely; storing it locally failed again.',
                );
            }

            $this->attempts->close(
                $attempt, ProvisioningStage::Persist,
                ProvisioningOutcome::RecoveredExisting, server: $remote,
            );

            return ProvisioningResult::provisioned(
                $order->fresh() ?? $order, $server, ProvisioningOutcome::RecoveredExisting,
            );
        }

        if ($matches->isTombstoned()) {
            // The token produced a machine and the provider has removed it. The
            // token is spent: a create call carrying it returns the tombstone,
            // and a fresh token would build something nobody bought. There is
            // no deliverable server and there cannot be one.
            return $this->refund(
                $order,
                ConfirmedNoServerOutcome::ReconciliationConfirmedNoServer,
                'The server created for this order has been deleted at the provider.',
            );
        }

        return $this->resolveAbsence($order);
    }

    /**
     * Orders a sweep should look at, oldest first.
     *
     * Bounded and paginated. An unbounded query over a growing table is how a
     * sweeper that ran fine for a year takes the application down.
     *
     * Returns null when the threshold setting cannot be read: automatic
     * selection fails closed rather than inventing a number that decides, by
     * accident, how long a customer waits before anyone notices.
     *
     * @return Collection<int, Order>|null
     */
    public function stuckOrders(?int $limit = null): ?Collection
    {
        $minutes = $this->settings->integer(SettingKey::ProvisioningStuckAfterMinutes);

        if ($minutes === null || $minutes < 0) {
            return null;
        }

        $limit ??= (int) config('cloudbot.provisioning.reconcile_batch', 100);
        $cutoff = CarbonImmutable::now()->subMinutes($minutes);

        return Order::query()
            ->whereIn('status', [OrderStatus::Provisioning->value, OrderStatus::NeedsAttention->value])
            ->whereNotNull('provisioning_uuid')
            ->where('updated_at', '<=', $cutoff)
            ->orderBy('updated_at')
            ->limit(max(1, $limit))
            ->get();
    }

    /**
     * A provider implementation for reading only.
     *
     * Resolved from the registry by code, which deliberately skips the
     * `enabled` check. Disabling a provider stops new spending; it must not
     * stop us finding out whether a customer's machine already exists.
     */
    public function readableProvider(string $code): ?CloudProviderInterface
    {
        try {
            return $this->providers->driver($code);
        } catch (ProviderException) {
            return null;
        }
    }

    /**
     * No remote server carries this token. Retry, or confirm the absence.
     */
    private function resolveAbsence(Order $order): ProvisioningResult
    {
        $max = (int) config('cloudbot.provisioning.max_attempts', 3);
        $made = $this->attempts->attemptCount($order);

        if ($made < $max) {
            // Attempts remain. The job may try again — and when it does it will
            // reconcile this same token before creating anything.
            return ProvisioningResult::retryable(
                $order,
                ProvisioningOutcome::TransientFailure,
                'No remote server carries this token; provisioning may be attempted again.',
            );
        }

        // Policy exhausted and the provider, read successfully, holds nothing.
        // That is a confirmed absence — the only kind that justifies a refund.
        return $this->refund(
            $order,
            ConfirmedNoServerOutcome::ReconciliationConfirmedNoServer,
            'Reconciliation found no server for this order after the retry policy was exhausted.',
        );
    }

    private function refund(Order $order, ConfirmedNoServerOutcome $outcome, string $reason): ProvisioningResult
    {
        $refunded = $this->refunds->refundConfirmedFailure($order, $outcome, $reason);

        return ProvisioningResult::refunded($refunded, ProvisioningOutcome::RejectedNoServer, $reason);
    }

    /**
     * @param  array<string, scalar|null>  $facts
     */
    private function park(
        Order $order,
        ProvisioningOutcome $outcome,
        string $reason,
        string $detail,
        array $facts = [],
    ): ProvisioningResult {
        $parked = DB::transaction(function () use ($order, $reason, $detail, $facts): Order {
            $parked = $order->status === OrderStatus::Provisioning
                ? $this->refunds->recordUncertainResult($order, $detail)
                : $order;

            $this->alerts->orderNeedsAttention($parked, $reason, $facts);

            return $parked;
        });

        return ProvisioningResult::needsAttention($parked, $outcome, $detail);
    }
}
