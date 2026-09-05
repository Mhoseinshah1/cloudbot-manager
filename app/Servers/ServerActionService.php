<?php

declare(strict_types=1);

namespace App\Servers;

use App\Audit\AuditRecorder;
use App\Cloud\Enums\ProviderErrorCategory;
use App\Enums\ServerActionStatus;
use App\Enums\ServerActionType;
use App\Models\Server;
use App\Models\ServerAction;
use App\Models\User;
use App\Outbox\OutboxTopic;
use App\Outbox\OutboxWriter;
use App\Servers\Exceptions\ServerActionNotAllowed;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Records what a customer asked us to do to a server, and nothing more.
 *
 * Nothing here calls a provider. This runs on the interactive worker, where a
 * customer is waiting and a provider call can block for minutes — and where a
 * timeout would leave a machine in a state nobody recorded. So the request is
 * written down, an intent to perform it is written beside it, and the work
 * happens on a worker built for waiting.
 *
 * The idempotency key is what stops a repeated request becoming a repeated
 * reboot. Telegram re-delivers, queues re-deliver, and a customer whose button
 * did not visibly respond presses it again; all of those resolve to one row,
 * and one row is one remote operation. The key is derived from the business
 * intent — the specific interaction, the specific delete confirmation — never
 * from callback data, which the customer controls.
 */
final readonly class ServerActionService
{
    public function __construct(
        private ServerAccess $servers,
        private OutboxWriter $outbox,
        private AuditRecorder $audit,
    ) {}

    /**
     * Ask for something to be done to one of this customer's servers.
     *
     * Returns fast, and returns the same action for a repeated request. The
     * caller cannot tell a first request from a duplicate, which is the point:
     * both mean "this should happen once".
     *
     * @param  array<string, scalar|null>  $metadata  Facts only.
     *
     * @throws ServerActionNotAllowed
     */
    public function request(
        User $customer,
        int|string $serverId,
        ServerActionType $action,
        string $idempotencyKey,
        array $metadata = [],
    ): ServerAction {
        if (! $customer->isActive()) {
            // Suspended and banned customers may look at their servers; they
            // may not operate them.
            throw ServerActionNotAllowed::inactiveCustomer();
        }

        // Scoped by owner in the query. The id came from a button, and a button
        // is a request rather than a claim about whose server this is.
        $server = $this->servers->findOrFail($customer, $serverId);

        $this->servers->assertSupported($server, $action);

        $existing = $this->findByKey($idempotencyKey);

        if ($existing instanceof ServerAction) {
            $this->assertSameRequest($existing, $server, $action);

            return $existing;
        }

        try {
            return DB::transaction(function () use ($customer, $server, $action, $idempotencyKey, $metadata): ServerAction {
                $recorded = ServerAction::query()->create([
                    'server_id' => $server->getKey(),
                    'actor_type' => ServerAction::ACTOR_CUSTOMER,
                    'actor_id' => $customer->getKey(),
                    'action' => $action->value,
                    'status' => ServerActionStatus::Pending->value,
                    'idempotency_key' => $idempotencyKey,
                    'metadata' => $metadata,
                    'requested_at' => CarbonImmutable::now(),
                ]);

                // Requested, not done, and only for the destructive one. A
                // delete that was asked for and never reached the provider
                // still has to be visible to whoever investigates a machine
                // that is still running; the rest are audited when they
                // actually happen, which is the fact worth having.
                $requestEvent = $action->requestAuditEvent();

                if ($requestEvent !== null) {
                    $this->audit->record(
                        $requestEvent,
                        actor: $customer,
                        subject: $recorded,
                        metadata: [
                            'server_action_id' => $recorded->getKey(),
                            'server_id' => $server->getKey(),
                            'user_id' => $customer->getKey(),
                            'action' => $action->value,
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
                        'user_id' => $customer->getKey(),
                        'action' => $action->value,
                    ],
                    self::requestKey($recorded),
                );

                return $recorded;
            });
        } catch (QueryException $exception) {
            // Two deliveries carrying one key arrived together.
            $winner = $this->findByKey($idempotencyKey);

            if ($winner instanceof ServerAction) {
                $this->assertSameRequest($winner, $server, $action);

                return $winner;
            }

            throw $exception;
        }
    }

    /**
     * Move an action on, once.
     *
     * Compare-and-set on the status, so a worker and a reconciler that both
     * decide an action finished cannot both believe they were the one that
     * settled it — which matters because settling a delete is what ends a
     * customer's service.
     */
    public function settle(
        ServerAction $action,
        ServerActionStatus $status,
        ?string $providerActionId = null,
        ?ProviderErrorCategory $category = null,
    ): bool {
        $attributes = [
            'status' => $status->value,
            'settled_at' => $status->isSettled() ? CarbonImmutable::now() : null,
            'updated_at' => now(),
        ];

        if ($providerActionId !== null) {
            $attributes['provider_action_id'] = $providerActionId;
        }

        $attributes['error_category'] = $category?->value;

        $affected = ServerAction::query()
            ->whereKey($action->getKey())
            ->whereIn('status', [ServerActionStatus::Pending->value, ServerActionStatus::Running->value])
            ->update($attributes);

        return $affected === 1;
    }

    /**
     * Claim one attempt at reaching the provider, durably.
     *
     * Reserved before the call rather than counted after it: a worker that dies
     * inside a delete has still spent its attempt, and counting afterwards is
     * how a crash loop sends the same destructive request forever. The bound is
     * in the WHERE clause, so two workers cannot both take the last one.
     *
     * @return bool Whether this worker may proceed.
     */
    public function reserveAttempt(ServerAction $action, int $maximum): bool
    {
        return ServerAction::query()
            ->whereKey($action->getKey())
            ->where('attempts', '<', $maximum)
            // Pending only. `Running` means the provider accepted an operation
            // and is still working on it, and initiating a second one would ask
            // for the same reboot or the same delete twice. The per-server lock
            // does not prevent that on its own: it serializes two workers, and
            // the second one simply arrives after the first has released. Only
            // the durable state can refuse it.
            ->where('status', ServerActionStatus::Pending->value)
            // The same fact from the other side. A provider handle means there
            // is an outstanding operation to poll, and polling is where it
            // belongs — never re-initiation.
            ->whereNull('provider_action_id')
            // The durable barrier, in the WHERE clause rather than only in a
            // caller's check. A prequeued duplicate cannot walk past a delay by
            // forgetting to look first.
            ->where(function (Builder $query): void {
                $query->whereNull('retry_after')->orWhere('retry_after', '<=', now());
            })
            ->update([
                'attempts' => DB::raw('attempts + 1'),
                // Cleared in the same statement that grants permission to call
                // the provider, and this is the whole point of doing it here.
                //
                // These two columns are evidence about the *last* provider
                // call: a category the enum calls retryable, and the time after
                // which another attempt is allowed. Leaving them in place while
                // a new attempt goes out makes them a lie the moment the call
                // leaves — and a worker that then dies leaves reconciliation
                // reading attempt N-1's "safely refused, nothing happened"
                // against attempt N, whose outcome nobody knows. That is how a
                // delete gets sent a second time.
                //
                // After this statement succeeds the row means exactly one
                // thing: a provider write may now be in flight and its outcome
                // is not yet known.
                'error_category' => null,
                'retry_after' => null,
                'updated_at' => now(),
            ]) === 1;
    }

    /**
     * Hold a retryable failure open until a stated time, without settling it.
     *
     * For a provider answer the category itself declares safe to repeat — a
     * rate limit, an outage, a transient error. Settling those as `failed`
     * threw away a customer's power off or delete because the provider was busy
     * for a minute, and a failed action is excluded from reconciliation, so the
     * remaining durable attempts were never spent.
     *
     * Two things are written and both matter. The category is what a later
     * sweep reads to know this action's last provider call completed with a
     * known-safe outcome — which is the difference between a retry that is safe
     * and one that might repeat a delete. The deadline is durable, so a
     * duplicate job already queued cannot walk past it.
     *
     * Compare-and-set on the open statuses, so this cannot reopen an action
     * somebody else has already settled.
     *
     * @return bool Whether this worker wrote the barrier.
     */
    public function postpone(ServerAction $action, int $seconds, ProviderErrorCategory $category): bool
    {
        return ServerAction::query()
            ->whereKey($action->getKey())
            ->whereIn('status', [ServerActionStatus::Pending->value, ServerActionStatus::Running->value])
            ->update([
                'retry_after' => CarbonImmutable::now()->addSeconds(max(1, $seconds)),
                'error_category' => $category->value,
                'updated_at' => now(),
            ]) === 1;
    }

    /**
     * Return a failed provider operation to a state a later attempt may use.
     *
     * For the one case where the provider's own answer is both terminal and
     * safe: the operation it accepted has ended in failure, and the normalized
     * category says repeating the request could work.
     *
     * Three things move together, and doing them in one statement is what makes
     * this safe rather than a sequence somebody can interleave. The status goes
     * back to pending, so a later execution may start a genuinely new
     * operation. The finished provider handle is cleared, because polling an
     * operation that has already ended is how a reconciler settles an action
     * from stale evidence. And a durable barrier is written, so the retry
     * cannot be immediate and cannot be walked past by a job that was already
     * queued.
     *
     * The attempt this consumed stays consumed. A provider write genuinely
     * happened; the budget is what stops this becoming a loop.
     *
     * Compare-and-set on the running state, so this cannot reopen an action
     * somebody else has already settled.
     *
     * @return bool Whether this worker made the transition.
     */
    public function reopenForRetry(ServerAction $action, int $seconds, ProviderErrorCategory $category): bool
    {
        return ServerAction::query()
            ->whereKey($action->getKey())
            ->whereIn('status', [ServerActionStatus::Pending->value, ServerActionStatus::Running->value])
            ->update([
                'status' => ServerActionStatus::Pending->value,
                'provider_action_id' => null,
                'error_category' => $category->value,
                'retry_after' => CarbonImmutable::now()->addSeconds(max(1, $seconds)),
                'settled_at' => null,
                'updated_at' => now(),
            ]) === 1;
    }

    /**
     * Whether this action's last provider call is known to have changed nothing.
     *
     * True only when a category the enum itself calls retryable was recorded
     * against an action that never received a provider handle. That pairing is
     * the evidence: the call completed, the provider refused it in a way that
     * takes no effect, and no operation is outstanding. Anything else — a
     * timeout, an uncertain result, an attempt reserved by a worker that then
     * died without recording an outcome — leaves open the possibility that the
     * request landed, and a second delete is not something to guess about.
     */
    public function lastCallIsKnownSafe(ServerAction $action): bool
    {
        return $action->provider_action_id === null
            && $action->error_category instanceof ProviderErrorCategory
            && $action->error_category->isRetryable();
    }

    /** This customer's action history for one server, newest first. */
    public function findByKey(string $idempotencyKey): ?ServerAction
    {
        $action = ServerAction::query()->where('idempotency_key', $idempotencyKey)->first();

        return $action instanceof ServerAction ? $action : null;
    }

    /** One request to perform one action, however many deliveries arrive. */
    public static function requestKey(ServerAction $action): string
    {
        return 'server_action:'.$action->getKey().':requested';
    }

    /**
     * Confirm a repeat describes the action it found.
     *
     * A key that resolves to a different server or a different operation is not
     * a retry, it is a collision — and answering it with the existing action
     * would tell a caller their reboot succeeded when what exists is somebody's
     * delete.
     *
     * @throws ServerActionNotAllowed
     */
    private function assertSameRequest(ServerAction $existing, Server $server, ServerActionType $action): void
    {
        if ($existing->server_id !== $server->getKey() || $existing->action !== $action) {
            throw ServerActionNotAllowed::noSuchServer();
        }
    }
}
