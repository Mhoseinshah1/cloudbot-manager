<?php

declare(strict_types=1);

namespace App\Provisioning;

use App\Audit\AuditEvent;
use App\Audit\AuditRecorder;
use App\Cloud\Contracts\CloudProviderInterface;
use App\Cloud\Data\CreateServerRequest;
use App\Cloud\Data\ProviderServerData;
use App\Cloud\Enums\ProviderErrorCategory;
use App\Cloud\Enums\ProviderServerStatus;
use App\Cloud\Exceptions\ProviderException;
use App\Cloud\ProviderManager;
use App\Enums\ConfirmedNoServerOutcome;
use App\Enums\OrderStatus;
use App\Enums\ProvisioningOutcome;
use App\Enums\ProvisioningStage;
use App\Enums\SettingKey;
use App\Models\Order;
use App\Models\Provider;
use App\Models\ProvisioningAttempt;
use App\Orders\RefundService;
use App\Provisioning\Data\ProvisioningPlan;
use App\Provisioning\Data\ProvisioningResult;
use App\Provisioning\Data\TokenMatches;
use App\Provisioning\Exceptions\RemoteIdentityConflict;
use App\Provisioning\Exceptions\RemoteIdentityMismatch;
use App\Settings\SettingsService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Turns a paid order into a server, safely, across two systems that cannot be
 * made atomic.
 *
 * The whole design follows from one fact: a database transaction and an HTTP
 * call to another company cannot commit or roll back together. Somewhere there
 * is always an instant where the provider has acted and we do not yet know it,
 * and every rule here exists to make sure that instant is survivable.
 *
 * The order is therefore fixed:
 *
 *   1. Commit the intent. `paid -> provisioning` by compare-and-set, and a
 *      provisioning token written in the same statement. This transaction
 *      closes before anything leaves the process, so if the worker dies one
 *      microsecond later, the durable record already says which order was about
 *      to be built and under which token.
 *   2. Only then coordinate. The Redis lock is taken after the commit, never
 *      instead of it.
 *   3. Ask the provider what it already holds for this token. Always, not only
 *      on retries: the cheapest way never to create a second machine is to look
 *      before creating the first.
 *   4. Check availability, create, validate the identity that comes back.
 *   5. Persist locally in one transaction.
 *
 * No provider call happens inside a database transaction. Not the availability
 * check, not the create, not the recovery lookup. Holding a row lock across
 * somebody else's network is how one slow provider stops an entire application.
 *
 * When the outcome is unknown, nothing is refunded. A create that timed out
 * after the provider received it is indistinguishable from one that was
 * refused, and the difference is a real machine; the order is parked and
 * reconciliation finds out.
 */
final readonly class ProvisioningService
{
    public function __construct(
        private ProviderManager $providers,
        private SettingsService $settings,
        private OrderPlanner $planner,
        private TokenLookup $tokens,
        private AttemptRecorder $attempts,
        private ServerPersister $persister,
        private ProvisioningLock $lock,
        private RefundService $refunds,
        private OperationalAlerts $alerts,
        private AuditRecorder $audit,
    ) {}

    /**
     * Build the server this order paid for.
     *
     * Returns rather than throws for the ordinary outcomes — paused, contended,
     * already delivered — because each needs a different response from the
     * caller and none of them is an error.
     */
    public function provision(Order $order): ProvisioningResult
    {
        // Before anything at all, including before the order is touched. The
        // switch is an operational pause, so the safest reading of it is the one
        // that leaves the order exactly where it was: still `paid`, still with
        // whatever token it already had, resumable the moment it is switched
        // back on. No provider call can happen from here.
        if ($this->provisioningIsPaused()) {
            return ProvisioningResult::paused($order);
        }

        $prepared = $this->prepare($order);

        if (! $prepared instanceof Order) {
            return ProvisioningResult::notEligible(
                $order->fresh() ?? $order,
                'That order is not waiting to be provisioned.',
            );
        }

        if ($prepared->status === OrderStatus::Provisioned) {
            $server = $prepared->server;

            return $server === null
                ? ProvisioningResult::notEligible($prepared, 'That order is already provisioned.')
                : ProvisioningResult::provisioned($prepared, $server, ProvisioningOutcome::RecoveredExisting);
        }

        // The lock is taken only now — after the token is durable. It
        // coordinates; it is never what makes this correct.
        $result = $this->lock->attempt($prepared, function () use ($prepared): ProvisioningResult {
            // Re-read inside the lock: between the commit and the lock another
            // worker may have finished the whole job.
            $fresh = Order::query()->whereKey($prepared->getKey())->first();

            if (! $fresh instanceof Order) {
                throw new ModelNotFoundException('That order no longer exists.');
            }

            return $this->runUnderLock($fresh);
        });

        return $result ?? ProvisioningResult::contended($prepared);
    }

    /**
     * Step 1 to 5: claim the order and commit its token.
     *
     * The compare-and-set and the token are written by one statement in one
     * transaction, so an order can never be `provisioning` without a token or
     * carry a token it never claimed. Returns null when this order is not ours
     * to take.
     *
     * An order already in `provisioning` or `needs_attention` is returned as-is:
     * it has been claimed before, it already has a token, and that token is the
     * one that must be used.
     */
    public function prepare(Order $order): ?Order
    {
        return DB::transaction(function () use ($order): ?Order {
            $locked = Order::query()->whereKey($order->getKey())->lockForUpdate()->first();

            if (! $locked instanceof Order) {
                throw new ModelNotFoundException('That order no longer exists.');
            }

            if ($locked->status === OrderStatus::Provisioned) {
                return $locked;
            }

            if (in_array($locked->status, [OrderStatus::Provisioning, OrderStatus::NeedsAttention], strict: true)) {
                // Resuming. The token that already exists is the only one that
                // may be used, and it is never regenerated.
                return $locked->provisioning_uuid === null
                    ? $this->assignTokenInPlace($locked)
                    : $locked;
            }

            if ($locked->status !== OrderStatus::Paid) {
                return null;
            }

            // Reuse before create. A previous claim that was rolled back to
            // `paid`, or an operator resetting a state, must not produce a
            // second token for the same intended machine.
            $token = $locked->provisioning_uuid ?? (string) Str::uuid();

            $claimed = DB::table('orders')
                ->where('id', $locked->getKey())
                ->where('status', OrderStatus::Paid->value)
                ->update([
                    'status' => OrderStatus::Provisioning->value,
                    'provisioning_uuid' => $token,
                    'updated_at' => now(),
                ]);

            if ($claimed !== 1) {
                return null;
            }

            $fresh = Order::query()->whereKey($locked->getKey())->first();

            if (! $fresh instanceof Order) {
                throw new ModelNotFoundException('That order no longer exists.');
            }

            $this->audit->record(
                AuditEvent::OrderProvisioningStarted,
                subject: $fresh,
                after: ['status' => $fresh->status->value],
                metadata: [
                    'order_id' => $fresh->getKey(),
                    'order_number' => $fresh->order_number,
                    'provisioning_uuid' => $fresh->provisioning_uuid,
                ],
            );

            return $fresh;
        });
    }

    /**
     * Everything after the token is durable and the lock is held.
     */
    private function runUnderLock(Order $order): ProvisioningResult
    {
        if ($order->status === OrderStatus::Provisioned) {
            $server = $order->server;

            return $server === null
                ? ProvisioningResult::notEligible($order, 'That order is already provisioned.')
                : ProvisioningResult::provisioned($order, $server, ProvisioningOutcome::RecoveredExisting);
        }

        if ($order->provisioning_uuid === null) {
            return ProvisioningResult::notEligible($order, 'That order has no provisioning token.');
        }

        $plan = $this->planner->plan($order);
        $provider = $this->providerForCreate($plan);

        if (! $provider instanceof CloudProviderInterface) {
            // Disabled, unregistered, or otherwise not spendable right now. No
            // create is attempted; the order keeps its token and waits.
            return ProvisioningResult::retryable(
                $order,
                ProvisioningOutcome::TransientFailure,
                'That provider is not currently able to create servers.',
            );
        }

        // Look before creating. Always — not only on a retry. A worker that died
        // after calling the provider leaves no attempt row to count, so counting
        // attempts is not a safe way to decide whether a machine might exist.
        try {
            $existing = $this->tokens->find($provider, (string) $order->provisioning_uuid);
        } catch (ProviderException $exception) {
            // A failed lookup is not evidence of absence. Nothing is created.
            return $this->afterProviderFailure($order, $plan, $exception, ProvisioningStage::BeforeCreate);
        }

        if ($existing->isAmbiguous()) {
            return $this->parkAmbiguous($order, $existing);
        }

        if ($existing->isUnique()) {
            // The provider already built this. Recover it rather than create.
            return $this->adoptRemote($order, $plan, $existing->sole(), ProvisioningOutcome::RecoveredExisting);
        }

        if ($existing->isTombstoned()) {
            // The token has been spent: its machine was created and then
            // removed. Calling create again with it returns the tombstone, not
            // a replacement, and a fresh token would build something nobody
            // bought — so this is a confirmed absence of a deliverable server.
            return $this->refundConfirmed(
                $order,
                $plan,
                ConfirmedNoServerOutcome::ReconciliationConfirmedNoServer,
                ProvisioningOutcome::RejectedNoServer,
                'The server created for this order has been deleted at the provider.',
            );
        }

        return $this->createFresh($order, $plan, $provider);
    }

    /**
     * Availability, then create. Both outside any transaction.
     */
    private function createFresh(Order $order, ProvisioningPlan $plan, CloudProviderInterface $provider): ProvisioningResult
    {
        $attempt = $this->attempts->open($order, ProvisioningStage::BeforeCreate, $plan);

        // Asked again, immediately before creating. Capacity changes between a
        // customer paying and a worker picking the job up, and a create against
        // a sold-out plan is a slower way to learn the same thing.
        try {
            $available = $provider->checkAvailability($plan->providerPlanCode, $plan->providerLocationCode);
        } catch (ProviderException $exception) {
            // A read failed. Nothing was sent that could create anything, so
            // whatever the category says, no server exists because of this.
            $this->attempts->close(
                $attempt, ProvisioningStage::BeforeCreate,
                ProvisioningOutcome::TransientFailure, $exception->category,
            );

            return $this->afterProviderFailure($order, $plan, $exception, ProvisioningStage::BeforeCreate);
        }

        if (! $available) {
            $this->attempts->close(
                $attempt, ProvisioningStage::BeforeCreate, ProvisioningOutcome::AvailabilityLost,
            );

            // Confirmed: no create was made, so no machine exists. This is the
            // one refund path that needs no reconciliation at all.
            return $this->refundConfirmed(
                $order,
                $plan,
                ConfirmedNoServerOutcome::AvailabilityLostNoServer,
                ProvisioningOutcome::AvailabilityLost,
                'That plan is no longer available in that location.',
            );
        }

        $request = new CreateServerRequest(
            // Exactly the committed token. Not a new one, not a derived one.
            provisioningToken: (string) $order->provisioning_uuid,
            providerPlanId: $plan->providerPlanCode,
            providerLocationId: $plan->providerLocationCode,
            providerImageId: $plan->providerImageCode,
            name: OrderPlanner::serverName($order),
            labels: OrderPlanner::labels($order),
        );

        try {
            $remote = $provider->createServer($request);
        } catch (ProviderException $exception) {
            return $this->afterCreateFailure($order, $plan, $attempt, $exception);
        } catch (Throwable $exception) {
            // An unexpected failure during a create is the worst case: it says
            // nothing about whether a machine exists. Treated as uncertain,
            // never as a failure.
            $this->attempts->close(
                $attempt, ProvisioningStage::Create,
                ProvisioningOutcome::Uncertain, ProviderErrorCategory::UncertainResult,
            );

            return $this->parkUncertain($order, 'The provider create did not complete cleanly.');
        }

        return $this->afterCreate($order, $plan, $attempt, $remote);
    }

    /**
     * The provider answered a create. Decide what its answer means.
     */
    private function afterCreate(
        Order $order,
        ProvisioningPlan $plan,
        ProvisioningAttempt $attempt,
        ProviderServerData $remote,
    ): ProvisioningResult {
        if ($remote->status === ProviderServerStatus::Deleted) {
            // The token's machine has already been removed. Create returned the
            // tombstone rather than building a replacement, which is the
            // contract working correctly.
            $this->attempts->close(
                $attempt, ProvisioningStage::Create, ProvisioningOutcome::RejectedNoServer,
                server: $remote,
            );

            return $this->refundConfirmed(
                $order,
                $plan,
                ConfirmedNoServerOutcome::ReconciliationConfirmedNoServer,
                ProvisioningOutcome::RejectedNoServer,
                'The server created for this order has been deleted at the provider.',
            );
        }

        if ($remote->status === ProviderServerStatus::Error) {
            // A machine exists and is broken. Not a confirmed absence, so not a
            // refund — and certainly not a second create.
            $this->attempts->close(
                $attempt, ProvisioningStage::Create, ProvisioningOutcome::Uncertain,
                server: $remote,
            );

            return $this->parkNeedsAttention(
                $order,
                ProvisioningOutcome::Uncertain,
                'provider_reported_error',
                'The provider reports this server is in an error state.',
                ['provider_server_id' => $remote->providerServerId],
            );
        }

        if ($remote->status !== ProviderServerStatus::Active) {
            // Still being built. The machine exists, so nothing may be created
            // again; the order stays in provisioning and a sweep revisits it.
            $this->attempts->close(
                $attempt, ProvisioningStage::Create, ProvisioningOutcome::InFlight, server: $remote,
            );

            return ProvisioningResult::remotePending(
                $order, 'The provider is still building this server.',
            );
        }

        return $this->persistCreated($order, $plan, $attempt, $remote, ProvisioningOutcome::Succeeded);
    }

    /**
     * Store an active remote server locally, and survive failing to.
     */
    private function persistCreated(
        Order $order,
        ProvisioningPlan $plan,
        ProvisioningAttempt $attempt,
        ProviderServerData $remote,
        ProvisioningOutcome $outcome,
    ): ProvisioningResult {
        try {
            $server = $this->persister->persist($order, $remote, $plan);
        } catch (RemoteIdentityMismatch|RemoteIdentityConflict $exception) {
            $this->attempts->close(
                $attempt, ProvisioningStage::Persist, ProvisioningOutcome::IdentityMismatch, server: $remote,
            );

            return $this->parkNeedsAttention(
                $order,
                ProvisioningOutcome::IdentityMismatch,
                'remote_identity_mismatch',
                $exception->getMessage(),
                ['provider_server_id' => $remote->providerServerId],
            );
        } catch (Throwable $exception) {
            // The remote machine exists and the local write failed. This is the
            // case the whole architecture is built around: no refund, no second
            // create, no new token. The token is durable, the machine carries
            // it, and reconciliation will find it and finish the job.
            $this->attempts->close(
                $attempt,
                ProvisioningStage::Persist,
                ProvisioningOutcome::RemoteCreatedLocalFailed,
                ProviderErrorCategory::LocalPersistenceError,
                $remote,
            );

            $this->alerts->providerFailure(
                $order,
                ProviderErrorCategory::LocalPersistenceError->value,
                [
                    'provider_server_id' => $remote->providerServerId,
                    'provisioning_uuid' => $order->provisioning_uuid,
                ],
            );

            return ProvisioningResult::retryable(
                $order,
                ProvisioningOutcome::RemoteCreatedLocalFailed,
                'The server exists remotely; storing it locally failed and will be retried.',
            );
        }

        $this->attempts->close($attempt, ProvisioningStage::Persist, $outcome, server: $remote);

        return ProvisioningResult::provisioned($order->fresh() ?? $order, $server, $outcome);
    }

    /**
     * Adopt a machine the provider already holds for this token.
     */
    private function adoptRemote(
        Order $order,
        ProvisioningPlan $plan,
        ?ProviderServerData $remote,
        ProvisioningOutcome $outcome,
    ): ProvisioningResult {
        if (! $remote instanceof ProviderServerData) {
            return ProvisioningResult::retryable(
                $order, ProvisioningOutcome::TransientFailure, 'No single remote server to adopt.',
            );
        }

        if ($remote->status !== ProviderServerStatus::Active) {
            return ProvisioningResult::remotePending(
                $order, 'The provider is still building this server.',
            );
        }

        $attempt = $this->attempts->open($order, ProvisioningStage::Persist, $plan);

        return $this->persistCreated($order, $plan, $attempt, $remote, $outcome);
    }

    /**
     * A create failed. What that means depends entirely on the category.
     */
    private function afterCreateFailure(
        Order $order,
        ProvisioningPlan $plan,
        ProvisioningAttempt $attempt,
        ProviderException $exception,
    ): ProvisioningResult {
        $category = $exception->category;

        if ($category->isOutcomeUnknown()) {
            // The single most consequential branch in the system. A machine may
            // exist. Refunding here hands back the money for a server the
            // customer still has, and creating again makes them a second one.
            $this->attempts->close(
                $attempt, ProvisioningStage::Create, ProvisioningOutcome::Uncertain, $category,
            );

            return $this->parkUncertain($order, $exception->getMessage());
        }

        if ($category === ProviderErrorCategory::OutOfStock) {
            $this->attempts->close(
                $attempt, ProvisioningStage::Create, ProvisioningOutcome::AvailabilityLost, $category,
            );

            return $this->refundConfirmed(
                $order,
                $plan,
                ConfirmedNoServerOutcome::AvailabilityLostNoServer,
                ProvisioningOutcome::AvailabilityLost,
                $exception->getMessage(),
            );
        }

        if ($this->isDeterministicRejection($category)) {
            // The provider said no, and said it in a way that repeating cannot
            // change. Nothing was created, so the customer's money goes back —
            // and an operator is told, because authentication and an empty
            // account are their problem to fix, not a customer's to wait out.
            $this->attempts->close(
                $attempt, ProvisioningStage::Create, ProvisioningOutcome::RejectedNoServer, $category,
            );

            $this->alerts->providerFailure($order, $category->value, [
                'provider_code' => $plan->providerCode,
            ]);

            return $this->refundConfirmed(
                $order,
                $plan,
                ConfirmedNoServerOutcome::ProviderRejectedNoServer,
                ProvisioningOutcome::RejectedNoServer,
                $exception->getMessage(),
            );
        }

        // Transient. Says nothing about whether a machine exists, so the next
        // attempt reconciles the token before it considers creating anything.
        $this->attempts->close(
            $attempt, ProvisioningStage::Create, ProvisioningOutcome::TransientFailure, $category,
        );

        return $this->exhaustedOrRetry($order, $plan, $exception->getMessage());
    }

    /**
     * A provider read failed before anything could have been created.
     */
    private function afterProviderFailure(
        Order $order,
        ProvisioningPlan $plan,
        ProviderException $exception,
        ProvisioningStage $stage,
    ): ProvisioningResult {
        if ($this->isDeterministicRejection($exception->category)) {
            $this->alerts->providerFailure($order, $exception->category->value, [
                'provider_code' => $plan->providerCode,
                'stage' => $stage->value,
            ]);
        }

        // Deliberately not a refund, even for a deterministic category. This
        // stage could not have created anything, but neither did it prove the
        // token has no machine — an authentication failure means we cannot see.
        return $this->exhaustedOrRetry($order, $plan, $exception->getMessage());
    }

    /**
     * Retry, or stop trying.
     *
     * "Stop trying" never means refund on its own. Only a confirmed absence
     * justifies that, and the check below is against the provider, not against
     * a counter.
     */
    private function exhaustedOrRetry(Order $order, ProvisioningPlan $plan, string $reason): ProvisioningResult
    {
        $max = (int) config('cloudbot.provisioning.max_attempts', 3);

        if ($this->attempts->attemptCount($order) < $max) {
            return ProvisioningResult::retryable($order, ProvisioningOutcome::TransientFailure, $reason);
        }

        // Out of attempts. A person decides now; the money has not moved.
        return $this->parkNeedsAttention(
            $order,
            ProvisioningOutcome::TransientFailure,
            'attempts_exhausted',
            $reason,
            ['attempts' => $this->attempts->attemptCount($order)],
        );
    }

    /**
     * Full refund for an outcome known to have left nothing behind.
     */
    private function refundConfirmed(
        Order $order,
        ProvisioningPlan $plan,
        ConfirmedNoServerOutcome $outcome,
        ProvisioningOutcome $attemptOutcome,
        string $reason,
    ): ProvisioningResult {
        // RefundService owns the money entirely: it proves the debit exists in
        // the ledger before crediting, keys the refund exactly once, and writes
        // its own audit and outbox. Nothing here touches a balance.
        $refunded = $this->refunds->refundConfirmedFailure($order, $outcome, $reason);

        return ProvisioningResult::refunded($refunded, $attemptOutcome, $reason);
    }

    /**
     * Park an order whose remote outcome is unknown.
     */
    private function parkUncertain(Order $order, string $reason): ProvisioningResult
    {
        return $this->parkNeedsAttention(
            $order, ProvisioningOutcome::Uncertain, 'uncertain_result', $reason,
        );
    }

    /**
     * Move an order to needs_attention, tell an operator, touch no money.
     *
     * @param  array<string, scalar|null>  $facts
     */
    private function parkNeedsAttention(
        Order $order,
        ProvisioningOutcome $outcome,
        string $reason,
        string $detail,
        array $facts = [],
    ): ProvisioningResult {
        // One transaction, so an order can never sit parked with nobody told.
        // RefundService opens its own; nested, that becomes a savepoint inside
        // this one and commits with it.
        $parked = DB::transaction(function () use ($order, $reason, $detail, $facts): Order {
            $parked = $order->status === OrderStatus::Provisioning
                // Phase 6's boundary, reused deliberately. It refuses to record
                // uncertainty against an order with nothing in flight, and it
                // can never produce `failed`.
                ? $this->refunds->recordUncertainResult($order, $detail)
                : $order;

            $this->alerts->orderNeedsAttention($parked, $reason, $facts);

            return $parked;
        });

        return ProvisioningResult::needsAttention($parked, $outcome, $detail);
    }

    /**
     * More than one machine claims this token. Never resolved automatically.
     */
    private function parkAmbiguous(Order $order, TokenMatches $matches): ProvisioningResult
    {
        return $this->parkNeedsAttention(
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

    /**
     * The provider implementation allowed to spend money, or null.
     *
     * Goes through `for()` rather than `driver()` on purpose: an operator who
     * disabled a provider meant it, and this is the path that creates servers.
     * Reconciliation uses the other door, because reading whether a machine
     * already exists is a safety check rather than a purchase.
     */
    private function providerForCreate(ProvisioningPlan $plan): ?CloudProviderInterface
    {
        $row = Provider::query()->whereKey($plan->providerId)->first();

        if (! $row instanceof Provider) {
            return null;
        }

        try {
            return $this->providers->for($row);
        } catch (ProviderException) {
            return null;
        }
    }

    /**
     * Whether the operator has paused provisioning.
     *
     * Fails closed. Absent or malformed means paused: nothing about a missing
     * row says it is safe to start spending money at a third party.
     */
    private function provisioningIsPaused(): bool
    {
        return $this->settings->boolean(SettingKey::ProvisioningEnabled) !== true;
    }

    /**
     * Categories where repeating the identical request cannot help, and where
     * the provider's answer is that nothing was made.
     */
    private function isDeterministicRejection(ProviderErrorCategory $category): bool
    {
        return in_array($category, [
            ProviderErrorCategory::Authentication,
            ProviderErrorCategory::Authorization,
            ProviderErrorCategory::InvalidRequest,
            ProviderErrorCategory::InsufficientProviderBalance,
        ], strict: true);
    }

    /**
     * Give a token to an order that is already claimed but somehow lacks one.
     */
    private function assignTokenInPlace(Order $locked): Order
    {
        DB::table('orders')
            ->where('id', $locked->getKey())
            ->whereNull('provisioning_uuid')
            ->update(['provisioning_uuid' => (string) Str::uuid(), 'updated_at' => now()]);

        $fresh = Order::query()->whereKey($locked->getKey())->first();

        if (! $fresh instanceof Order) {
            throw new ModelNotFoundException('That order no longer exists.');
        }

        return $fresh;
    }
}
