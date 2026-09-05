<?php

declare(strict_types=1);

use App\Authorization\RoleProvisioner;
use App\Cloud\Data\ProviderActionData;
use App\Cloud\Enums\ProviderActionStatus;
use App\Cloud\Enums\ProviderErrorCategory;
use App\Cloud\Fake\FakeProvider;
use App\Enums\ServerActionStatus;
use App\Enums\ServerActionType;
use App\Enums\ServerStatus;
use App\Jobs\ExecuteServerActionJob;
use App\Models\OutboxMessage;
use App\Models\Server;
use App\Models\ServerAction;
use App\Provisioning\ProvisioningService;
use App\Servers\ServerActionReconciler;
use App\Servers\ServerActionService;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Tests\Support\Provisioning\ProvisioningFloor;
use Tests\Support\Provisioning\Simulator;

/**
 * RCH-002, RCH-003 and RCH-004: what a server action row is allowed to mean.
 *
 * Three residual defects, all of them the same shape — durable state saying
 * something that was true a moment ago and is not true now, read by a
 * reconciler as though it were current.
 *
 * The retry evidence survived a new attempt. A rate limit on attempt one wrote
 * "safely refused, nothing happened", and reserving attempt two left it there;
 * a worker that died mid-call then left reconciliation reading attempt one's
 * innocence against attempt two's unknown outcome, and sending a delete again.
 *
 * A Running action could be initiated twice. The status explicitly means the
 * provider accepted an operation and is still working on it, but the reservation
 * allowed it and the per-server lock does not help: it serializes two workers,
 * and the second simply arrives after the first releases.
 *
 * And a failed provider action was recorded as `failed` carrying
 * `TransientProviderError` — a category that reports `isRetryable()` on a status
 * that is settled and excluded from reconciliation. The row asserted both "try
 * again" and "never again", and the customer's request was quietly dropped.
 */
beforeEach(function (): void {
    app(RoleProvisioner::class)->sync();

    $this->floor = ProvisioningFloor::open();
    app(ProvisioningService::class)->provision($this->floor->paidOrder());

    $this->server = Server::query()->sole();
    $this->actions = app(ServerActionService::class);
    $this->reconciler = app(ServerActionReconciler::class);

    config(['queue.default' => 'sync']);
});

/** An accepted action whose one execution delivery never reached the provider. */
function acceptedAction(ServerActionType $type, string $key): ServerAction
{
    $action = test()->actions->request(test()->floor->customer, test()->server->getKey(), $type, $key);

    OutboxMessage::query()
        ->where('deduplication_key', ServerActionService::requestKey($action))
        ->update(['processed_at' => now()]);

    return $action->fresh();
}

/** Old enough for the reconciler to look at. */
function ageForSweep(ServerAction $action): ServerAction
{
    ServerAction::query()->whereKey($action->getKey())
        ->update(['requested_at' => CarbonImmutable::now()->subHour()]);

    return $action->fresh();
}

/** A provider answer that accepted the work and is still doing it. */
function runningAction(string $command, string $serverId, string $id = 'act-running'): ProviderActionData
{
    return new ProviderActionData(
        providerActionId: $id,
        command: $command,
        status: ProviderActionStatus::Running,
        providerServerId: $serverId,
        startedAt: new DateTimeImmutable,
        finishedAt: null,
        metadata: App\Cloud\Data\SafeMetadata::pick(['command' => $command], ['command']),
    );
}

it('RCH-002: clears the previous call\'s retry evidence when a new attempt is reserved', function (): void {
    $action = acceptedAction(ServerActionType::Delete, 'stale-evidence-delete');

    // Attempt one is rate limited, so the row records a category the enum calls
    // retryable and a barrier.
    Simulator::script()->rejectOperation('deleteServer', ProviderErrorCategory::RateLimited);
    app()->call([new ExecuteServerActionJob((int) $action->getKey()), 'handle']);

    $afterFirst = $action->fresh();

    expect($afterFirst->status)->toBe(ServerActionStatus::Pending)
        ->and($afterFirst->error_category)->toBe(ProviderErrorCategory::RateLimited)
        ->and($afterFirst->retry_after)->not->toBeNull()
        ->and($afterFirst->attempts)->toBe(1);

    // The barrier expires and attempt two is reserved — and then the worker
    // dies, exactly as a killed process does, before any outcome is written.
    ServerAction::query()->whereKey($action->getKey())
        ->update(['retry_after' => CarbonImmutable::now()->subMinute()]);

    expect($this->actions->reserveAttempt($action->fresh(), 3))->toBeTrue();

    $afterReservation = $action->fresh();

    // The row now means one thing only: a provider write may be in flight and
    // nobody knows its outcome. Attempt one's innocence is gone.
    expect($afterReservation->attempts)->toBe(2)
        ->and($afterReservation->error_category)->toBeNull()
        ->and($afterReservation->retry_after)->toBeNull()
        ->and($this->actions->lastCallIsKnownSafe($afterReservation))->toBeFalse();

    $scripted = Simulator::script();

    $this->reconciler->reconcile(ageForSweep($action));

    // No second delete, and the machine is untouched.
    expect($scripted->callCount('deleteServer'))->toBe(0)
        ->and($action->fresh()->status)->toBe(ServerActionStatus::NeedsAttention)
        ->and($action->fresh()->error_category)->toBe(ProviderErrorCategory::UncertainResult)
        ->and((int) $action->fresh()->attempts)->toBe(2)
        ->and($this->server->fresh()->status)->toBe(ServerStatus::Active);
});

it('RCH-003: a duplicate job never re-initiates an operation the provider is running', function (): void {
    $action = acceptedAction(ServerActionType::Reboot, 'running-reboot');
    $serverId = (string) $this->server->provider_server_id;

    $scripted = Simulator::script();
    $scripted->onOperation('reboot', static fn (): ProviderActionData => runningAction('reboot', $serverId));

    // Job A initiates it. The provider accepts and is still working.
    app()->call([new ExecuteServerActionJob((int) $action->getKey()), 'handle']);

    $afterFirst = $action->fresh();

    expect($scripted->callCount('reboot'))->toBe(1)
        ->and($afterFirst->status)->toBe(ServerActionStatus::Running)
        ->and($afterFirst->provider_action_id)->toBe('act-running')
        ->and($afterFirst->attempts)->toBe(1);

    // Job B was already queued when that happened. It takes the lock, re-reads
    // an open action — and must not ask for a second reboot.
    app()->call([new ExecuteServerActionJob((int) $action->getKey()), 'handle']);

    $afterSecond = $action->fresh();

    expect($scripted->callCount('reboot'))->toBe(1)
        ->and($afterSecond->attempts)->toBe(1)
        ->and($afterSecond->provider_action_id)->toBe('act-running')
        ->and($afterSecond->status)->toBe(ServerActionStatus::Running);

    // The reservation itself refuses, so the database is what enforces it.
    expect($this->actions->reserveAttempt($afterSecond, 3))->toBeFalse()
        ->and((int) $action->fresh()->attempts)->toBe(1);

    // Polling settles it, once. The operation the provider was working on has
    // finished, and that is what the reconciler asks about — never the machine,
    // because a running server looks the same whether it rebooted or not.
    $scripted->onGetAction(static fn (): ProviderActionData => new ProviderActionData(
        providerActionId: 'act-running',
        command: 'reboot',
        status: ProviderActionStatus::Success,
        providerServerId: $serverId,
        startedAt: new DateTimeImmutable,
        finishedAt: new DateTimeImmutable,
        metadata: App\Cloud\Data\SafeMetadata::pick([], []),
    ));

    $this->reconciler->reconcile(ageForSweep($action));

    expect($action->fresh()->status)->toBe(ServerActionStatus::Succeeded)
        ->and($scripted->callCount('reboot'))->toBe(1);
});

it('RCH-003: a running delete is polled, never sent again', function (): void {
    $action = acceptedAction(ServerActionType::Delete, 'running-delete');
    $serverId = (string) $this->server->provider_server_id;

    $scripted = Simulator::script();
    $scripted->onOperation('deleteServer', static fn (): ProviderActionData => runningAction('delete', $serverId, 'act-del'));

    app()->call([new ExecuteServerActionJob((int) $action->getKey()), 'handle']);
    app()->call([new ExecuteServerActionJob((int) $action->getKey()), 'handle']);

    expect($scripted->callCount('deleteServer'))->toBe(1)
        ->and($action->fresh()->status)->toBe(ServerActionStatus::Running)
        ->and((int) $action->fresh()->attempts)->toBe(1)
        // The machine is still there: the scripted answer accepted the delete
        // without performing it, which is exactly a provider working on it.
        ->and($this->server->fresh()->status)->toBe(ServerStatus::Active);
});

it('RCH-004: a retryable provider action error reopens the action instead of failing it', function (): void {
    $action = acceptedAction(ServerActionType::PowerOff, 'running-then-error');
    $serverId = (string) $this->server->provider_server_id;

    $scripted = Simulator::script();
    $scripted->onOperation('powerOff', static fn (): ProviderActionData => runningAction('power_off', $serverId, 'act-po'));

    app()->call([new ExecuteServerActionJob((int) $action->getKey()), 'handle']);

    expect($action->fresh()->status)->toBe(ServerActionStatus::Running);

    // The reconciler polls it and the provider says the operation failed, with
    // a category that is safe to repeat.
    $scripted->onGetAction(static fn (): ProviderActionData => new ProviderActionData(
        providerActionId: 'act-po',
        command: 'power_off',
        status: ProviderActionStatus::Error,
        providerServerId: $serverId,
        startedAt: new DateTimeImmutable,
        finishedAt: new DateTimeImmutable,
        metadata: App\Cloud\Data\SafeMetadata::pick([], []),
        errorCategory: ProviderErrorCategory::RateLimited,
    ));

    $this->reconciler->reconcile(ageForSweep($action));

    $reopened = $action->fresh();

    // Not failed. The finished operation's handle is cleared so nothing polls
    // it again, and a durable barrier holds the next attempt back.
    expect($reopened->status)->toBe(ServerActionStatus::Pending)
        ->and($reopened->provider_action_id)->toBeNull()
        ->and($reopened->error_category)->toBe(ProviderErrorCategory::RateLimited)
        ->and($reopened->retry_after)->not->toBeNull()
        ->and($reopened->retry_after->isFuture())->toBeTrue()
        // The attempt stays spent: a provider write genuinely happened.
        ->and((int) $reopened->attempts)->toBe(1);

    // An immediate duplicate does nothing.
    app()->call([new ExecuteServerActionJob((int) $action->getKey()), 'handle']);

    expect($scripted->callCount('powerOff'))->toBe(1)
        ->and((int) $action->fresh()->attempts)->toBe(1);

    // After the barrier, exactly one new provider write is allowed, and it works.
    Simulator::script();
    ServerAction::query()->whereKey($action->getKey())
        ->update(['retry_after' => CarbonImmutable::now()->subMinute()]);

    $this->reconciler->reconcile(ageForSweep($action));

    expect($action->fresh()->status)->toBe(ServerActionStatus::Succeeded)
        ->and((int) $action->fresh()->attempts)->toBe(2);
});

it('RCH-004: an unclassified provider action error parks rather than inventing a category', function (): void {
    $action = acceptedAction(ServerActionType::Reboot, 'unclassified-error');
    $serverId = (string) $this->server->provider_server_id;

    $scripted = Simulator::script();
    $scripted->onOperation('reboot', static fn (): ProviderActionData => runningAction('reboot', $serverId, 'act-rb'));

    app()->call([new ExecuteServerActionJob((int) $action->getKey()), 'handle']);

    $scripted->onGetAction(static fn (): ProviderActionData => new ProviderActionData(
        providerActionId: 'act-rb',
        command: 'reboot',
        status: ProviderActionStatus::Error,
        providerServerId: $serverId,
        startedAt: new DateTimeImmutable,
        finishedAt: new DateTimeImmutable,
        metadata: App\Cloud\Data\SafeMetadata::pick([], []),
        // The adapter could not classify it. That is a fact nobody has
        // established, not permission to repeat a reboot.
        errorCategory: null,
    ));

    $this->reconciler->reconcile(ageForSweep($action));

    $parked = $action->fresh();

    expect($parked->status)->toBe(ServerActionStatus::NeedsAttention)
        ->and($parked->error_category)->toBe(ProviderErrorCategory::UncertainResult)
        ->and($scripted->callCount('reboot'))->toBe(1);

    // And nothing later revives it into a blind retry.
    $this->reconciler->reconcile(ageForSweep($action));

    expect($scripted->callCount('reboot'))->toBe(1)
        ->and($action->fresh()->status)->toBe(ServerActionStatus::NeedsAttention);
});

it('RCH-004: a deterministic provider action error settles as failed', function (): void {
    $action = acceptedAction(ServerActionType::PowerOn, 'deterministic-error');
    $serverId = (string) $this->server->provider_server_id;

    $scripted = Simulator::script();
    $scripted->onOperation('powerOn', static fn (): ProviderActionData => runningAction('power_on', $serverId, 'act-on'));

    app()->call([new ExecuteServerActionJob((int) $action->getKey()), 'handle']);

    $scripted->onGetAction(static fn (): ProviderActionData => new ProviderActionData(
        providerActionId: 'act-on',
        command: 'power_on',
        status: ProviderActionStatus::Error,
        providerServerId: $serverId,
        startedAt: new DateTimeImmutable,
        finishedAt: new DateTimeImmutable,
        metadata: App\Cloud\Data\SafeMetadata::pick([], []),
        errorCategory: ProviderErrorCategory::InvalidRequest,
    ));

    $this->reconciler->reconcile(ageForSweep($action));

    expect($action->fresh()->status)->toBe(ServerActionStatus::Failed)
        ->and($action->fresh()->error_category)->toBe(ProviderErrorCategory::InvalidRequest);
});

it('never records a settled failure carrying a retryable category', function (): void {
    // The invariant behind RCH-004, asserted over everything the suite has
    // written rather than over one path. A row saying "terminally failed" and
    // "safe to try again" at once is a contradiction the reconciler cannot act
    // on, so no production path may produce it.
    $this->floor->setProvisioning(true);

    foreach ([ServerActionType::PowerOff, ServerActionType::Reboot, ServerActionType::Delete] as $index => $type) {
        $action = acceptedAction($type, 'invariant-'.$index);

        Simulator::script()->rejectOperation(
            match ($type) {
                ServerActionType::PowerOff => 'powerOff',
                ServerActionType::Reboot => 'reboot',
                default => 'deleteServer',
            },
            ProviderErrorCategory::TransientProviderError,
        );

        app()->call([new ExecuteServerActionJob((int) $action->getKey()), 'handle']);
    }

    // And the other route to a failure: an operation the provider accepted and
    // then reported as failed. This is the path the contradiction actually came
    // from, so an invariant that only covered thrown exceptions would have
    // watched it happen.
    $serverId = (string) $this->server->provider_server_id;
    $polled = acceptedAction(ServerActionType::PowerOff, 'invariant-polled');

    $scripted = Simulator::script();
    $scripted->onOperation('powerOff', static fn (): ProviderActionData => runningAction('power_off', $serverId, 'act-inv'));
    app()->call([new ExecuteServerActionJob((int) $polled->getKey()), 'handle']);

    $scripted->onGetAction(static fn (): ProviderActionData => new ProviderActionData(
        providerActionId: 'act-inv',
        command: 'power_off',
        status: ProviderActionStatus::Error,
        providerServerId: $serverId,
        startedAt: new DateTimeImmutable,
        finishedAt: new DateTimeImmutable,
        metadata: App\Cloud\Data\SafeMetadata::pick([], []),
        errorCategory: ProviderErrorCategory::TransientProviderError,
    ));

    $this->reconciler->reconcile(ageForSweep($polled));

    $contradictions = ServerAction::query()
        ->where('status', ServerActionStatus::Failed->value)
        ->whereNotNull('error_category')
        ->get()
        ->filter(fn (ServerAction $action): bool => $action->error_category?->isRetryable() === true);

    expect($contradictions)->toBeEmpty();
});

it('offers no path that both settles failed and claims a retryable category', function (): void {
    // The same invariant stated against the executor's own vocabulary: every
    // category it may settle `Failed` with must be one repeating cannot help.
    foreach (ProviderErrorCategory::cases() as $category) {
        if (! $category->isRetryable()) {
            continue;
        }

        $action = acceptedAction(ServerActionType::PowerOff, 'vocab-'.$category->value);

        Simulator::script()->rejectOperation('powerOff', $category);
        app()->call([new ExecuteServerActionJob((int) $action->getKey()), 'handle']);

        expect($action->fresh()->status)->not->toBe(ServerActionStatus::Failed);
    }

    expect(FakeProvider::CODE)->toBe('fake');
});
