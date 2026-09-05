<?php

declare(strict_types=1);

namespace App\Servers;

use App\Audit\AuditRecorder;
use App\Cloud\Capabilities\SupportsPowerControl;
use App\Cloud\Capabilities\SupportsReboot;
use App\Cloud\Contracts\CloudProviderInterface;
use App\Cloud\Data\ProviderActionData;
use App\Cloud\Enums\ProviderActionStatus;
use App\Cloud\Enums\ProviderErrorCategory;
use App\Cloud\Enums\ProviderServerStatus;
use App\Cloud\Exceptions\ProviderException;
use App\Cloud\ProviderManager;
use App\Enums\ServerActionStatus;
use App\Enums\ServerActionType;
use App\Enums\ServerPowerState;
use App\Models\Server;
use App\Models\ServerAction;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Support\Facades\Log;

/**
 * Performs one recorded server action at the provider.
 *
 * This is the only place in Phase 9 that talks to a cloud provider, and it runs
 * on the provisioning worker. Nothing on the interactive Telegram worker calls
 * out: a customer pressing a button must not wait on somebody else's provider,
 * and a request that timed out mid-flight must not be a request nobody wrote
 * down.
 *
 * The hard part is not the call, it is the answers that are not answers. A
 * provider that times out may have rebooted the machine or may not have
 * touched it, and the two look identical from here. So an uncertain outcome
 * never becomes a retry: for power there is a fact that settles it — the
 * machine's actual state, which is what was asked for — and for a reboot there
 * is not, because a running server is exactly what both "rebooted" and "never
 * rebooted" look like. Those become somebody's decision rather than a second
 * request.
 *
 * No database transaction is open across a provider call. The two cannot be
 * made atomic, and pretending otherwise holds a PostgreSQL transaction for the
 * length of a network timeout.
 */
final readonly class ServerActionExecutor
{
    public function __construct(
        private ProviderManager $providers,
        private ServerActionService $actions,
        private ServerTerminationService $termination,
        private ServerAccess $access,
        private AuditRecorder $audit,
        private Config $config,
    ) {}

    /**
     * Carry out one action, or explain why it did not happen.
     *
     * Called with this server's coordination lock already held.
     */
    public function execute(ServerAction $action, Server $server): void
    {
        if (! $action->isOpen()) {
            // Settled while this job waited. The common case for a duplicated
            // dispatch, and deliberately silent.
            return;
        }

        if ($action->action === ServerActionType::RootPasswordReveal) {
            // Never a provider operation. It is settled where it is delivered.
            return;
        }

        if (! $this->access->isLive($server) && $action->action !== ServerActionType::Delete) {
            // Nothing to operate. Deleting is exempt: a terminated record whose
            // remote machine is still there is precisely what a delete fixes.
            $this->actions->settle($action, ServerActionStatus::Failed, category: ProviderErrorCategory::InvalidRequest);

            return;
        }

        $provider = $this->resolveProvider($action, $server);

        if (! $provider instanceof CloudProviderInterface) {
            return;
        }

        // Asked again, here, rather than trusted from when the button was
        // drawn. An operator can disable a provider between the two, and an
        // adapter that no longer offers reboot must not be sent one.
        if (! $this->offers($provider, $action->action)) {
            $this->actions->settle($action, ServerActionStatus::Failed, category: ProviderErrorCategory::InvalidRequest);

            return;
        }

        // The durable barrier, checked after the lock and before the attempt.
        // A provider that asked us to wait wrote a time to PostgreSQL, and a
        // duplicate job that was already queued when it did would otherwise
        // call straight past it — releasing the worker that received the
        // refusal delays only that worker. Nothing is sent, nothing is
        // recorded, and no attempt is spent; the reconciler offers this action
        // again once it is genuinely due.
        if (! $action->mayAttemptNow()) {
            return;
        }

        // Reserved before the call, never counted after it. A worker that dies
        // inside a delete has still spent its attempt; counting afterwards is
        // how a crash loop sends the same destructive request forever.
        if (! $this->actions->reserveAttempt($action, $this->maximumAttempts())) {
            $this->exhausted($action);

            return;
        }

        try {
            $result = $this->perform($provider, $action->action, $server);
        } catch (ProviderException $exception) {
            $this->handleFailure($action, $server, $provider, $exception);

            return;
        }

        $this->handleResult($action, $server, $result);
    }

    /**
     * Ask the provider what became of an action it accepted earlier.
     *
     * Used by the reconciler. A provider that cannot be read is not an action
     * that failed — saying so would let a network blip settle a delete.
     *
     * @return bool Whether the caller should queue execution work for this
     *              action once it has released the server's lock. The dispatch
     *              deliberately does not happen here: a job queued from inside
     *              the lock it needs would find it held by its own caller.
     */
    public function poll(ServerAction $action, Server $server): bool
    {
        if (! $action->isOpen()) {
            return false;
        }

        $provider = $this->resolveProvider($action, $server);

        if (! $provider instanceof CloudProviderInterface) {
            return false;
        }

        if ($action->provider_action_id === null) {
            return $this->reconcileWithoutActionId($action, $server, $provider);
        }

        try {
            $result = $provider->getAction($action->provider_action_id);
        } catch (ProviderException $exception) {
            // Could not read it. Left open on purpose.
            Log::info('server_action.poll_failed', [
                'server_action_id' => $action->getKey(),
                'category' => $exception->category->value,
            ]);

            return false;
        }

        $this->handleResult($action, $server, $result);

        return false;
    }

    /**
     * What a settled provider answer means locally.
     */
    private function handleResult(ServerAction $action, Server $server, ProviderActionData $result): void
    {
        if ($result->status === ProviderActionStatus::Running) {
            // Accepted and still working. The identifier is stored so the
            // reconciler can ask about this operation rather than guess from
            // the machine's state.
            $this->actions->settle($action, ServerActionStatus::Running, providerActionId: $result->providerActionId);

            return;
        }

        if ($result->status === ProviderActionStatus::Error) {
            $this->actions->settle(
                $action,
                ServerActionStatus::Failed,
                providerActionId: $result->providerActionId,
                category: ProviderErrorCategory::TransientProviderError,
            );

            return;
        }

        $this->succeed($action, $server, $result->providerActionId);
    }

    /**
     * Record success, and bring the local record into line.
     */
    private function succeed(ServerAction $action, Server $server, ?string $providerActionId): void
    {
        if ($action->action === ServerActionType::Delete) {
            // The machine is gone. Ending the customer's service is a local
            // transaction of its own, and it is what settles the action.
            $this->termination->finalize($action);

            return;
        }

        if (! $this->actions->settle($action, ServerActionStatus::Succeeded, providerActionId: $providerActionId)) {
            // Somebody else settled it. Their audit entry stands; a second one
            // would say a customer's server was powered on twice.
            return;
        }

        $intended = $action->action->intendedPowerState();

        if ($intended instanceof ServerPowerState) {
            // A local correction, not a claim. Inventory reconciliation still
            // owns provider truth; this just stops the customer's next screen
            // showing the state they have already changed.
            $server->forceFill(['power_state' => $intended->value])->save();
        }

        $this->audit->record(
            $action->action->completionAuditEvent(),
            subject: $server,
            metadata: [
                'server_id' => $server->getKey(),
                'user_id' => $server->user_id,
                'server_action_id' => $action->getKey(),
                'action' => $action->action->value,
            ],
        );
    }

    /**
     * What a thrown provider error means.
     *
     * The category decides, never the message: a provider's error text quotes
     * back the request, and the request carries credentials.
     */
    private function handleFailure(
        ServerAction $action,
        Server $server,
        CloudProviderInterface $provider,
        ProviderException $exception,
    ): void {
        if ($exception->category->isOutcomeUnknown()) {
            // Nobody knows whether it happened. Never repeat it blindly; look
            // for a fact that settles the question instead.
            $this->reconcileUncertain($action, $server, $provider, $exception->category);

            return;
        }

        if ($exception->category->isRetryable()) {
            // The category itself says repeating this could work — a rate
            // limit, a short outage, a transient error. Settling it as failed
            // threw a customer's power off or delete away because the provider
            // was busy for a minute, and left the action's remaining durable
            // attempts permanently unspent, because a failed action is
            // excluded from reconciliation.
            //
            // So it stays open, with the category recorded as evidence that
            // this call completed and changed nothing, and a durable deadline
            // that every worker honours. The attempt it spent stays spent, and
            // the attempt cap is what stops this being forever.
            $this->actions->postpone($action, $this->retryAfterSeconds(), $exception->category);

            Log::info('server_action.retryable_failure', [
                'server_action_id' => $action->getKey(),
                'action' => $action->action->value,
                'category' => $exception->category->value,
            ]);

            return;
        }

        // Deterministic, and nothing was created or changed by it. Repeating
        // the identical request cannot produce a different answer.
        $this->actions->settle($action, ServerActionStatus::Failed, category: $exception->category);
    }

    /**
     * An uncertain outcome, resolved by asking what is true rather than retrying.
     */
    private function reconcileUncertain(
        ServerAction $action,
        Server $server,
        CloudProviderInterface $provider,
        ProviderErrorCategory $category,
    ): void {
        $settled = $this->settleFromRemoteState($action, $server, $provider);

        if ($settled) {
            return;
        }

        // Either the provider could not be read, or the machine's state does
        // not answer the question. A reboot never has an answer here: a running
        // server looks the same whether it rebooted or was never touched, so
        // sending another one could restart a customer's machine a second time.
        $this->actions->settle($action, ServerActionStatus::NeedsAttention, category: $category);

        Log::warning('server_action.uncertain', [
            'server_action_id' => $action->getKey(),
            'server_id' => $server->getKey(),
            'action' => $action->action->value,
            'category' => $category->value,
        ]);
    }

    /**
     * An open action with no provider handle: decide from the machine itself.
     */
    private function reconcileWithoutActionId(
        ServerAction $action,
        Server $server,
        CloudProviderInterface $provider,
    ): bool {
        if ($this->settleFromRemoteState($action, $server, $provider)) {
            return false;
        }

        // Nothing to poll and nothing conclusive to read. Whether that means
        // "send it" or "ask a person" turns on one question, and it is not the
        // one that looks obvious: not whether the action is still pending, but
        // whether this action's provider write is known never to have started.
        //
        // Redispatching every pending action with no provider handle would be
        // the naive repair and it is dangerous — an attempt reserved by a
        // worker that then died is not proof the provider was never called, and
        // a second delete is not recoverable.
        if ($this->mayRedispatch($action)) {
            // Same action row, same idempotency key, same business intent. A
            // repeated sweep before the job runs is safe: the job re-reads
            // under the server's lock, honours the same durable barrier, and
            // reserves its own attempt.
            //
            // The caller queues it, once this lock is released.
            Log::info('server_action.redispatch_requested', [
                'server_action_id' => $action->getKey(),
                'action' => $action->action->value,
                'attempts' => $action->attempts,
            ]);

            return true;
        }

        if ($action->attempts > 0 && ! $this->actions->lastCallIsKnownSafe($action)) {
            // An attempt was reserved and no durable outcome was ever written.
            // That is not evidence the provider did nothing — the reservation
            // commits before the call precisely so a worker that dies inside
            // one has still spent it. Repeating a reboot or a delete on that
            // basis is exactly the guess this system does not make.
            $this->actions->settle(
                $action, ServerActionStatus::NeedsAttention, category: ProviderErrorCategory::UncertainResult,
            );

            Log::warning('server_action.attempt_without_outcome', [
                'server_action_id' => $action->getKey(),
                'action' => $action->action->value,
                'attempts' => $action->attempts,
            ]);

            return false;
        }

        // Out of attempts on a known-safe failure. Nothing is outstanding at
        // the provider, but the customer's request never happened, so it is a
        // person's to decide rather than a silent success.
        if ($action->attempts >= $this->maximumAttempts()) {
            $this->exhausted($action);
        }

        // Otherwise the barrier has simply not expired yet. Left open, and the
        // next sweep after it does will redispatch.
        return false;
    }

    /**
     * Whether it is safe to send this action to the provider again.
     *
     * Two cases, and only two.
     *
     * Nothing was ever attempted: `attempts` is zero, so no reservation was
     * taken, so no provider call can have started. The original execution job
     * was lost before it reached the provider — dropped, never delivered, or
     * refused a lock until its single delivery was gone — and redispatching it
     * is simply doing the work that was already promised.
     *
     * Or the last call completed and is known to have changed nothing: a
     * category the enum calls retryable was recorded against an action that
     * received no provider handle. That pairing is evidence, not optimism.
     *
     * Both are additionally bounded by the durable attempt budget and the
     * durable retry barrier, so neither can become a loop.
     */
    private function mayRedispatch(ServerAction $action): bool
    {
        if (! $action->mayAttemptNow() || $action->attempts >= $this->maximumAttempts()) {
            return false;
        }

        return $action->attempts === 0 || $this->actions->lastCallIsKnownSafe($action);
    }

    /**
     * How long to wait before retrying a provider that asked us to.
     *
     * Bounded and configurable. The typed provider contract carries a category
     * but no retry-after time, so there is nothing provider-supplied to honour
     * here and a number is not invented per call site.
     */
    private function retryAfterSeconds(): int
    {
        return max(1, (int) $this->config->get('cloudbot.server_actions.retry_after_seconds', 120));
    }

    /**
     * Settle from what the provider says the machine is, if that answers it.
     *
     * @return bool Whether the action was settled.
     */
    private function settleFromRemoteState(
        ServerAction $action,
        Server $server,
        CloudProviderInterface $provider,
    ): bool {
        try {
            $remote = $provider->getServer($server->provider_server_id);
        } catch (ProviderException $exception) {
            // The lookup failed. That says nothing about whether the machine
            // is there — an invalid request, a rejected credential, a timeout
            // and a rate limit all happen while a customer's server is running
            // perfectly well. Nothing is settled and nothing is claimed.
            Log::info('server_action.lookup_failed', [
                'server_action_id' => $action->getKey(),
                'category' => $exception->category->value,
            ]);

            return false;
        }

        if ($action->action === ServerActionType::Delete) {
            // Two answers end a deletion, and only these two: the provider says
            // the machine is deleted, or the provider says there is no such
            // machine. Both are the provider affirmatively answering the
            // question; neither is a failure that resembles one.
            if ($remote === null || $remote->status === ProviderServerStatus::Deleted) {
                $this->termination->finalize($action);

                return true;
            }

            // Still running. Nothing is settled and nothing is claimed; the
            // action stays open for another bounded attempt.
            return false;
        }

        if ($remote === null) {
            // The machine this action targets is gone, so powering or
            // rebooting it will never succeed. Not settled here: a server that
            // has vanished from under a live local record is inventory drift,
            // and the sweep whose job that is owns the correction.
            return false;
        }

        $intended = $action->action->intendedPowerState();

        if (! $intended instanceof ServerPowerState) {
            // Reboot. There is no state that means "it rebooted".
            return false;
        }

        if (ServerPowerState::fromProvider($remote->powerState) !== $intended) {
            return false;
        }

        // The machine is in the state that was asked for. Whether this request
        // or an earlier one put it there does not matter: what the customer
        // asked for is true.
        $this->succeed($action, $server, null);

        return true;
    }

    private function perform(
        CloudProviderInterface $provider,
        ServerActionType $action,
        Server $server,
    ): ProviderActionData {
        $id = $server->provider_server_id;

        return match ($action) {
            ServerActionType::PowerOn => $this->powerControl($provider)->powerOn($id),
            ServerActionType::PowerOff => $this->powerControl($provider)->powerOff($id),
            ServerActionType::Reboot => $this->rebooter($provider)->reboot($id),
            ServerActionType::Delete => $provider->deleteServer($id),
            ServerActionType::RootPasswordReveal => throw new \LogicException('A reveal never reaches a provider.'),
        };
    }

    private function powerControl(CloudProviderInterface $provider): SupportsPowerControl
    {
        if (! $provider instanceof SupportsPowerControl) {
            throw ProviderException::make(
                ProviderErrorCategory::InvalidRequest,
                $provider->code(),
                'This provider does not offer power control.',
            );
        }

        return $provider;
    }

    private function rebooter(CloudProviderInterface $provider): SupportsReboot
    {
        if (! $provider instanceof SupportsReboot) {
            throw ProviderException::make(
                ProviderErrorCategory::InvalidRequest,
                $provider->code(),
                'This provider does not offer reboot.',
            );
        }

        return $provider;
    }

    private function offers(CloudProviderInterface $provider, ServerActionType $action): bool
    {
        $capability = $action->requiredCapability();

        return $capability === null || $capability->isOfferedBy($provider);
    }

    private function resolveProvider(ServerAction $action, Server $server): ?CloudProviderInterface
    {
        try {
            return $this->providers->for($server->provider);
        } catch (ProviderException $exception) {
            // Disabled, or a code this build does not implement. Not a failure
            // of the action: an operator turned something off, and honouring
            // that is the point of the switch. Left open to be picked up when
            // the provider returns.
            Log::info('server_action.provider_unavailable', [
                'server_action_id' => $action->getKey(),
                'category' => $exception->category->value,
            ]);

            return null;
        }
    }

    private function exhausted(ServerAction $action): void
    {
        // Out of attempts, and deliberately not marked failed: a destructive
        // request that ran out of tries may still have reached the provider on
        // one of them.
        $this->actions->settle($action, ServerActionStatus::NeedsAttention, category: ProviderErrorCategory::UncertainResult);

        Log::warning('server_action.attempts_exhausted', [
            'server_action_id' => $action->getKey(),
            'action' => $action->action->value,
        ]);
    }

    private function maximumAttempts(): int
    {
        return max(1, (int) $this->config->get('cloudbot.server_actions.max_attempts', 3));
    }
}
