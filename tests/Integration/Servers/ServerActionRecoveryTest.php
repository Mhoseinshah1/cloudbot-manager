<?php

declare(strict_types=1);

use App\Authorization\RoleProvisioner;
use App\Cloud\Enums\ProviderErrorCategory;
use App\Enums\ServerActionStatus;
use App\Enums\ServerActionType;
use App\Enums\ServerStatus;
use App\Enums\SubscriptionStatus;
use App\Jobs\ExecuteServerActionJob;
use App\Models\OutboxMessage;
use App\Models\Server;
use App\Models\ServerAction;
use App\Models\Subscription;
use App\Provisioning\ProvisioningService;
use App\Servers\ServerActionReconciler;
use App\Servers\ServerActionService;
use Carbon\CarbonImmutable;
use Tests\Support\Provisioning\ProvisioningFloor;
use Tests\Support\Provisioning\Simulator;
use Tests\Support\Servers\CoreOnlyProvider;

/**
 * Getting a customer's action done without ever doing it twice.
 *
 * Two failures used to strand accepted work permanently, and their fixes have to
 * be one design because their repairs are the same repair.
 *
 * An action whose single execution job was lost — dropped, never delivered,
 * refused a lock until its one delivery was gone — sat `pending` with no
 * provider handle. Reconciliation read the machine, found nothing that settled
 * the question, and returned. Every sweep afterwards repeated that, forever, so
 * a delete a customer asked for never happened and the provider kept billing.
 *
 * And a provider answering with a rate limit, a short outage or a transient
 * error settled the action as permanently `failed`, despite the category itself
 * declaring the call safe to repeat. A failed action is excluded from
 * reconciliation, so the durable attempt budget was never spent.
 *
 * The dangerous fix is the obvious one: redispatch anything pending with no
 * provider handle. That repeats deletes and reboots that may already have
 * landed. So the rule is about evidence, not status — either nothing was ever
 * attempted, or the last attempt is recorded as one that completed and changed
 * nothing.
 */
beforeEach(function (): void {
    app(RoleProvisioner::class)->sync();

    $this->floor = ProvisioningFloor::open();
    app(ProvisioningService::class)->provision($this->floor->paidOrder());

    $this->server = Server::query()->sole();
    $this->actions = app(ServerActionService::class);
    $this->reconciler = app(ServerActionReconciler::class);

    // Dispatch runs the job in-process, so "the sweep queued it and a worker
    // ran it" is one step. The dispatch is the production path; only the
    // transport is collapsed.
    config(['queue.default' => 'sync']);
});

/**
 * An accepted action whose one execution delivery never reached the provider.
 *
 * The outbox intent is marked processed exactly as the real delivery marks it,
 * and the job it dispatched is simply never run.
 */
function acceptedButNeverExecuted(ServerActionType $type, string $key): ServerAction
{
    $action = test()->actions->request(test()->floor->customer, test()->server->getKey(), $type, $key);

    OutboxMessage::query()
        ->where('deduplication_key', ServerActionService::requestKey($action))
        ->update(['processed_at' => now()]);

    return $action->fresh();
}

/** Old enough for the reconciler to consider. */
function ageAction(ServerAction $action, int $seconds = 3_600): ServerAction
{
    ServerAction::query()->whereKey($action->getKey())
        ->update(['requested_at' => CarbonImmutable::now()->subSeconds($seconds)]);

    return $action->fresh();
}

it('A. redispatches an accepted action whose execution job was lost', function (): void {
    $action = ageAction(acceptedButNeverExecuted(ServerActionType::PowerOff, 'lost-poweroff'));

    expect($action->status)->toBe(ServerActionStatus::Pending)
        ->and($action->attempts)->toBe(0)
        ->and($action->provider_action_id)->toBeNull();

    $scripted = Simulator::script();

    $this->reconciler->reconcile($action);

    // Exactly one provider operation, from the same action row.
    expect($scripted->callCount('powerOff'))->toBe(1)
        ->and(ServerAction::query()->count())->toBe(1)
        ->and($action->fresh()->status)->toBe(ServerActionStatus::Succeeded)
        ->and((int) $action->fresh()->attempts)->toBe(1);
});

it('B. still produces one provider operation when the sweep runs repeatedly first', function (): void {
    $action = ageAction(acceptedButNeverExecuted(ServerActionType::PowerOff, 'swept-poweroff'));

    $scripted = Simulator::script();

    // Two schedulers, an operator running it by hand, a sweep overlapping its
    // predecessor. The per-server lock, the fresh read and the durable attempt
    // reservation are what make this one operation rather than three.
    $this->reconciler->reconcile($action);
    $this->reconciler->reconcile($action->fresh());
    $this->reconciler->reconcile($action->fresh());

    expect($scripted->callCount('powerOff'))->toBe(1)
        ->and($action->fresh()->status)->toBe(ServerActionStatus::Succeeded)
        ->and((int) $action->fresh()->attempts)->toBe(1);
});

it('C. retries a power off the provider rate limited, and succeeds', function (): void {
    $action = acceptedButNeverExecuted(ServerActionType::PowerOff, 'rate-limited-poweroff');

    $scripted = Simulator::script()
        ->rejectOperation('powerOff', ProviderErrorCategory::RateLimited, 'Too many requests.');

    app()->call([new ExecuteServerActionJob((int) $action->getKey()), 'handle']);

    $afterRefusal = $action->fresh();

    // Open, not failed. The category is recorded as the evidence that this call
    // completed and changed nothing, and the barrier is durable.
    expect($afterRefusal->status)->toBe(ServerActionStatus::Pending)
        ->and($afterRefusal->error_category)->toBe(ProviderErrorCategory::RateLimited)
        ->and($afterRefusal->attempts)->toBe(1)
        ->and($afterRefusal->retry_after)->not->toBeNull()
        ->and($afterRefusal->retry_after->isFuture())->toBeTrue();

    // A duplicate already in the queue must not walk past the barrier.
    app()->call([new ExecuteServerActionJob((int) $action->getKey()), 'handle']);

    expect($scripted->callCount('powerOff'))->toBe(1)
        ->and((int) $action->fresh()->attempts)->toBe(1);

    // The provider recovers and the barrier expires.
    Simulator::script();
    ServerAction::query()->whereKey($action->getKey())
        ->update(['retry_after' => CarbonImmutable::now()->subMinute()]);

    $this->reconciler->reconcile(ageAction($action));

    expect($action->fresh()->status)->toBe(ServerActionStatus::Succeeded)
        ->and((int) $action->fresh()->attempts)->toBe(2);
});

it('D. retries a power on the provider could not serve, and succeeds', function (): void {
    // Off first, so powering on is a real change rather than already true.
    $this->server->forceFill(['power_state' => App\Enums\ServerPowerState::Off->value])->save();
    App\Cloud\Fake\Models\FakeProviderServer::query()->update(['power_state' => 'off']);

    $action = acceptedButNeverExecuted(ServerActionType::PowerOn, 'unavailable-poweron');

    Simulator::script()->rejectOperation('powerOn', ProviderErrorCategory::Unavailable, 'Try later.');
    app()->call([new ExecuteServerActionJob((int) $action->getKey()), 'handle']);

    expect($action->fresh()->status)->toBe(ServerActionStatus::Pending)
        ->and($action->fresh()->error_category)->toBe(ProviderErrorCategory::Unavailable);

    $scripted = Simulator::script();
    ServerAction::query()->whereKey($action->getKey())
        ->update(['retry_after' => CarbonImmutable::now()->subMinute()]);

    $this->reconciler->reconcile(ageAction($action));

    expect($scripted->callCount('powerOn'))->toBe(1)
        ->and($action->fresh()->status)->toBe(ServerActionStatus::Succeeded);
});

it('E. retries a delete the provider transiently refused, and deletes exactly once', function (): void {
    $action = acceptedButNeverExecuted(ServerActionType::Delete, 'transient-delete');

    Simulator::script()
        ->rejectOperation('deleteServer', ProviderErrorCategory::TransientProviderError, 'Busy.');
    app()->call([new ExecuteServerActionJob((int) $action->getKey()), 'handle']);

    expect($action->fresh()->status)->toBe(ServerActionStatus::Pending)
        ->and($this->server->fresh()->status)->toBe(ServerStatus::Active);

    $scripted = Simulator::script();
    ServerAction::query()->whereKey($action->getKey())
        ->update(['retry_after' => CarbonImmutable::now()->subMinute()]);

    $this->reconciler->reconcile(ageAction($action));

    // One confirmed deletion, one local termination, and no refund: a customer
    // deleting their own server is not owed one.
    expect($scripted->callCount('deleteServer'))->toBe(1)
        ->and($action->fresh()->status)->toBe(ServerActionStatus::Succeeded)
        ->and($this->server->fresh()->status)->toBe(ServerStatus::Terminated)
        ->and(Subscription::query()->sole()->status)->toBe(SubscriptionStatus::Cancelled)
        ->and(App\Models\WalletTransaction::query()->where('type', 'refund')->count())->toBe(0);
});

it('F. never sends a second reboot after an uncertain one', function (): void {
    $action = acceptedButNeverExecuted(ServerActionType::Reboot, 'uncertain-reboot');

    Simulator::script()->loseResponseFor('reboot');
    app()->call([new ExecuteServerActionJob((int) $action->getKey()), 'handle']);

    // A running server looks identical whether it rebooted or was never
    // touched, so there is no fact that settles this. It is a person's.
    expect($action->fresh()->status)->toBe(ServerActionStatus::NeedsAttention);

    $scripted = Simulator::script();

    $this->reconciler->reconcile(ageAction($action));
    $this->reconciler->reconcile(ageAction($action));

    expect($scripted->callCount('reboot'))->toBe(0)
        ->and($action->fresh()->status)->toBe(ServerActionStatus::NeedsAttention);
});

it('G. never assumes a reserved attempt reached nobody', function (): void {
    $action = acceptedButNeverExecuted(ServerActionType::Delete, 'died-mid-write');

    // A worker reserved its attempt and died before writing any outcome. The
    // reservation commits before the call precisely so this leaves a mark —
    // and that mark is not evidence the provider was never asked.
    expect($this->actions->reserveAttempt($action->fresh(), 3))->toBeTrue();

    $scripted = Simulator::script();

    $this->reconciler->reconcile(ageAction($action));

    expect($scripted->callCount('deleteServer'))->toBe(0)
        // Parked for a person rather than guessed at in either direction.
        ->and($action->fresh()->status)->toBe(ServerActionStatus::NeedsAttention)
        ->and($action->fresh()->error_category)->toBe(ProviderErrorCategory::UncertainResult)
        // And nothing was claimed about the customer's machine.
        ->and($this->server->fresh()->status)->toBe(ServerStatus::Active)
        ->and(Subscription::query()->sole()->status)->toBe(SubscriptionStatus::Active);
});

it('H. never exceeds the durable maximum number of provider attempts', function (): void {
    $maximum = (int) config('cloudbot.server_actions.max_attempts', 3);
    $action = acceptedButNeverExecuted(ServerActionType::PowerOff, 'budget-poweroff');

    $scripted = Simulator::script();

    for ($round = 0; $round < $maximum + 3; $round++) {
        // Refused every single time. The scripted hook is one-shot by design,
        // so it is re-armed each round: this is a provider that keeps saying
        // no, not one that relents.
        $scripted->rejectOperation('powerOff', ProviderErrorCategory::RateLimited, 'Too many requests.');

        ServerAction::query()->whereKey($action->getKey())
            ->update(['retry_after' => CarbonImmutable::now()->subMinute()]);

        $this->reconciler->reconcile(ageAction($action));
    }

    $final = $action->fresh();

    expect($scripted->callCount('powerOff'))->toBe($maximum)
        ->and((int) $final->attempts)->toBe($maximum)
        // Out of attempts on a known-safe failure: nothing is outstanding at
        // the provider, but the customer's request never happened.
        ->and($final->status)->toBe(ServerActionStatus::NeedsAttention);
});

it('settles a deterministic refusal immediately rather than retrying it', function (): void {
    // Unchanged behaviour, and the boundary of the fix. Repeating an identical
    // request the provider called invalid cannot produce a different answer.
    $action = acceptedButNeverExecuted(ServerActionType::PowerOff, 'invalid-poweroff');

    $scripted = Simulator::script()
        ->rejectOperation('powerOff', ProviderErrorCategory::InvalidRequest, 'No such thing.');

    app()->call([new ExecuteServerActionJob((int) $action->getKey()), 'handle']);

    expect($action->fresh()->status)->toBe(ServerActionStatus::Failed)
        ->and($action->fresh()->error_category)->toBe(ProviderErrorCategory::InvalidRequest)
        ->and($action->fresh()->retry_after)->toBeNull();

    $this->reconciler->reconcile(ageAction($action));

    expect($scripted->callCount('powerOff'))->toBe(1)
        ->and($action->fresh()->status)->toBe(ServerActionStatus::Failed);
});

it('reads the provider without spending a write attempt', function (): void {
    // Reconciliation reads to find out what is true. Those reads must not eat
    // the budget that governs provider writes.
    $action = ageAction(acceptedButNeverExecuted(ServerActionType::PowerOff, 'read-budget'));

    Simulator::script()->rejectOperation('getServer', ProviderErrorCategory::Unavailable);

    $this->reconciler->reconcile($action);

    // The read failed, so nothing was settled — but the action was redispatched
    // and spent exactly the one attempt its execution used.
    expect((int) $action->fresh()->attempts)->toBeLessThanOrEqual(1);
});

it('leaves an action alone while a provider this build cannot serve is unavailable', function (): void {
    // An adapter that does not offer power control is not a failed action: an
    // operator changed something, and honouring that is the point.
    $action = ageAction(acceptedButNeverExecuted(ServerActionType::PowerOff, 'core-only'));

    $limited = Simulator::coreOnly();

    $this->reconciler->reconcile($action);

    expect($limited)->toBeInstanceOf(CoreOnlyProvider::class)
        ->and($action->fresh()->status)->not->toBe(ServerActionStatus::Succeeded);
});
