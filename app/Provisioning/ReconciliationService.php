<?php

declare(strict_types=1);

namespace App\Provisioning;

use App\Audit\AuditEvent;
use App\Audit\AuditRecorder;
use App\Cloud\Contracts\CloudProviderInterface;
use App\Cloud\Data\ProviderServerData;
use App\Cloud\Data\SensitiveRootCredential;
use App\Cloud\Enums\ProviderServerStatus;
use App\Cloud\Exceptions\ProviderException;
use App\Cloud\ProviderManager;
use App\Enums\ConfirmedNoServerOutcome;
use App\Enums\CredentialEvidence;
use App\Enums\OrderStatus;
use App\Enums\ProvisioningOutcome;
use App\Enums\ProvisioningStage;
use App\Enums\SettingKey;
use App\Models\Order;
use App\Models\Server;
use App\Orders\RefundService;
use App\Provisioning\Data\ProvisioningPlan;
use App\Provisioning\Data\ProvisioningResult;
use App\Provisioning\Exceptions\RemoteIdentityConflict;
use App\Provisioning\Exceptions\RemoteIdentityMismatch;
use App\Settings\SettingsService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
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
        private CreateBudget $budget,
        private ServerPersister $persister,
        private RefundService $refunds,
        private OperationalAlerts $alerts,
        private AuditRecorder $audit,
        private ProvisioningLock $lock,
        private CredentialRecovery $credentials,
    ) {}

    /**
     * Resolve one order against what its provider actually holds.
     *
     * Runs under the same per-order lock provisioning takes, and that is the
     * whole point. Without it this method could read the provider while a
     * worker's create call was still in flight, see nothing carrying the token,
     * find the create budget already spent by the reservation that call made —
     * and refund a customer whose machine was about to exist. Live server, full
     * refund, no way back.
     *
     * The cheap eligibility questions below are asked first because they change
     * nothing and answer most calls without waiting on anybody. Every decision
     * that reads a provider, moves money, persists a server or schedules work
     * happens after the lock, on a row read after the lock.
     *
     * A lock somebody else holds is not a reason to guess. It returns contended:
     * no provider call, no refund, no dispatch, and emphatically no inference
     * about what the provider holds — the worker that owns the lock is the one
     * finding that out.
     */
    public function reconcile(Order $order): ProvisioningResult
    {
        // No state changes here, so it is safe outside the lock. It only avoids
        // queueing behind a provider call to answer a question the order's own
        // status already settles.
        $ineligible = $this->ineligibleReason($order);

        if ($ineligible instanceof ProvisioningResult) {
            return $ineligible;
        }

        $result = $this->lock->attempt($order, function () use ($order): ProvisioningResult {
            // Read after the lock, never before it. The pre-lock instance was
            // loaded while another worker may have been part-way through this
            // order's create, and every decision below turns on facts that
            // worker was in the middle of changing.
            $fresh = Order::query()->whereKey($order->getKey())->first();

            if (! $fresh instanceof Order) {
                throw new ModelNotFoundException('That order no longer exists.');
            }

            $ineligible = $this->ineligibleReason($fresh);

            return $ineligible instanceof ProvisioningResult
                ? $ineligible
                : $this->reconcileUnderLock($fresh);
        });

        // Contended carries mayDispatch = false, so a sweep that could not get
        // the lock queues no create-capable work behind the worker holding it.
        return $result ?? ProvisioningResult::contended($order);
    }

    /**
     * The questions that need no provider and change nothing.
     *
     * Returns a result when this order is not reconcilable, or null when it is.
     */
    private function ineligibleReason(Order $order): ?ProvisioningResult
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

        return null;
    }

    /**
     * Everything that reads a provider, moves money, or schedules work.
     *
     * Called only with this order's provisioning lock held and with `$order`
     * read after that lock was taken.
     */
    private function reconcileUnderLock(Order $order): ProvisioningResult
    {
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

            // A machine that exists and has never been handed over. Whatever
            // password its create response carried is gone — it lived in the
            // memory of a worker that died, and no provider read will ever
            // reveal it. One is issued now, before anything is delivered.
            $credential = $this->credentialFor($order, $plan, $provider, $remote);

            if ($credential instanceof ProvisioningResult) {
                return $credential;
            }

            $attempt = $this->attempts->open($order, ProvisioningStage::Persist, $plan);

            try {
                $server = $this->persister->persist($order, $remote, $plan, credential: $credential);
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
     * A usable root password for a machine that exists but was never delivered.
     *
     * Returns a credential, or a result the caller must return unchanged.
     *
     * Every branch here is about a machine that genuinely exists and is
     * genuinely billable, which rules out two answers that would otherwise look
     * reasonable. It never refunds: the customer's money bought a server that is
     * running. And it never delivers without a credential: an order marked
     * provisioned is an order the system says the customer has, and saying that
     * about a machine nobody can log into is worse than admitting the problem.
     */
    private function credentialFor(
        Order $order,
        ProvisioningPlan $plan,
        CloudProviderInterface $provider,
        ProviderServerData $remote,
    ): SensitiveRootCredential|ProvisioningResult|null {
        // Already delivered. Its credential is on file, and rotating now would
        // lock out a customer who has been given the one it replaces.
        if (Server::query()->where('order_id', $order->getKey())->exists()) {
            return null;
        }

        $evidence = $this->attempts->credentialEvidence($order);

        if ($evidence === CredentialEvidence::KnownNone) {
            // A create was durably observed to have issued no password, so
            // there is nothing to recover and nothing to rotate. Delivering
            // this machine credential-free is what the create actually said.
            //
            // Note what is *not* being read here: the remote DTO. It carries no
            // credential by construction, so its silence says nothing, and
            // inferring "credentialless" from it would be inventing evidence.
            return null;
        }

        $recovered = $this->credentials->recover($order, $plan, $provider, $remote->providerServerId);

        if ($recovered instanceof SensitiveRootCredential) {
            return $recovered;
        }

        if ($recovered === CredentialRecovery::Retryable) {
            // Budget remains, so nothing is claimed and nobody is told. Not
            // retryableNow: dispatching create-capable work for an order whose
            // machine already exists would be work for nothing.
            return ProvisioningResult::retryable(
                $order,
                ProvisioningOutcome::TransientFailure,
                'A root credential for this server could not be issued yet.',
            );
        }

        return $this->park(
            $order,
            ProvisioningOutcome::Uncertain,
            $recovered,
            'The server exists but no usable root credential could be issued for it.',
            ['provider_server_id' => $remote->providerServerId],
        );
    }

    /**
     * No remote server carries this token. Retry, or confirm the absence.
     */
    private function resolveAbsence(Order $order): ProvisioningResult
    {
        // Measured against the durable create budget, not the number of
        // forensic rows. Reconciliation and persistence attempts write rows
        // without ever reaching a create, and counting those would refund a
        // customer whose order has not yet asked for a server even once.
        if (! $this->budget->isExhausted($order)) {
            // A lost delivery, and this is what repairs it. The provider was
            // read successfully and holds nothing, the token is unspent and the
            // budget has room — so provisioning work is safe to schedule, and
            // the caller does schedule it. Returning the word "retryable" and
            // stopping there would leave the order stuck forever.
            return ProvisioningResult::retryableNow(
                $order,
                ProvisioningOutcome::TransientFailure,
                'No remote server carries this token; provisioning is being attempted again.',
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
