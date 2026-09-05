<?php

declare(strict_types=1);

use App\Authorization\RoleProvisioner;
use App\Cloud\Enums\ProviderErrorCategory;
use App\Enums\ServerActionStatus;
use App\Enums\ServerActionType;
use App\Jobs\ExecuteServerActionJob;
use App\Models\OutboxMessage;
use App\Models\Server;
use App\Models\ServerAction;
use App\Provisioning\ProvisioningService;
use App\Servers\ServerActionService;
use Tests\Support\Provisioning\ProvisioningFloor;
use Tests\Support\Provisioning\Simulator;

/**
 * RCH-006. What a reserved-but-unresolved action row is allowed to authorize.
 *
 * RCH-002 made the reservation clear the previous call's retry evidence, which
 * was right and which is what creates this. The row a successful reservation
 * leaves behind is:
 *
 *     status = pending, provider_action_id = NULL,
 *     attempts > 0, error_category = NULL, retry_after = NULL
 *
 * and read carelessly that is indistinguishable from ordinary pending work. It
 * is the opposite. It means a worker has claimed this action, a provider write
 * may be in flight this second, and nobody has written down what became of it.
 *
 * The per-server lock is not what refuses the duplicate. It is coordination and
 * it is allowed to fail: a TTL expires while a call is still outstanding, Redis
 * goes away, a stalled worker's lock is released underneath it. A second job
 * that arrives afterwards reaches PostgreSQL with that row in front of it, and
 * PostgreSQL is the only thing that can say no.
 *
 * There is a second failure in the same shape, and it is the more surprising
 * one. When the budget is one, the duplicate's reservation is refused — and the
 * old code read a refused reservation as exhaustion, parking the action in
 * `needs_attention`. The worker still inside the successful call could then no
 * longer settle its own result, because the compare-and-set it settles with
 * only accepts an open action. A duplicate delivery could fail an operation
 * that was actively succeeding.
 */
beforeEach(function (): void {
    app(RoleProvisioner::class)->sync();

    $this->floor = ProvisioningFloor::open();
    app(ProvisioningService::class)->provision($this->floor->paidOrder());

    $this->server = Server::query()->sole();
    $this->actions = app(ServerActionService::class);

    config(['queue.default' => 'sync']);
});

/** An accepted action whose one execution delivery never reached the provider. */
function reservationAction(ServerActionType $type, string $key): ServerAction
{
    $action = test()->actions->request(test()->floor->customer, test()->server->getKey(), $type, $key);

    OutboxMessage::query()
        ->where('deduplication_key', ServerActionService::requestKey($action))
        ->update(['processed_at' => now()]);

    return $action->fresh();
}

/** The configured provider-write budget. */
function attemptBudget(): int
{
    return max(1, (int) config('cloudbot.server_actions.max_attempts', 3));
}

it('RCH-006 test 1: a duplicate job sends nothing while a reserved delete is unresolved', function (): void {
    $scripted = Simulator::script();
    $action = reservationAction(ServerActionType::Delete, 'in-flight-delete');

    // Worker A. It has taken the action's attempt through the real reservation
    // and is now inside deleteServer(); nothing has come back yet.
    expect($this->actions->reserveAttempt($action, attemptBudget()))->toBeTrue();

    $reserved = $action->fresh();

    expect($reserved->attempts)->toBe(1)
        ->and($reserved->status)->toBe(ServerActionStatus::Pending)
        ->and($reserved->provider_action_id)->toBeNull()
        // The shape the whole finding is about: a claimed attempt with no
        // recorded outcome and no retry evidence.
        ->and($reserved->error_category)->toBeNull()
        ->and($reserved->retry_after)->toBeNull()
        ->and($this->actions->lastCallIsKnownSafe($reserved))->toBeFalse();

    // Worker B: an ordinary duplicate delivery of the execution job, with the
    // lock free because Worker A is not holding it in this process.
    app()->call([new ExecuteServerActionJob((int) $action->getKey()), 'handle']);

    $after = $action->fresh();

    expect($scripted->callCount('deleteServer'))->toBe(0)
        // The budget was not spent a second time.
        ->and($after->attempts)->toBe(1)
        // And nothing was decided on Worker A's behalf.
        ->and($after->status)->toBe(ServerActionStatus::Pending)
        ->and($after->status)->not->toBe(ServerActionStatus::Failed)
        ->and($after->status)->not->toBe(ServerActionStatus::NeedsAttention)
        ->and($after->settled_at)->toBeNull()
        ->and($after->error_category)->toBeNull()
        // The server is still the customer's, because no second delete went out.
        ->and(Server::query()->whereKey($this->server->getKey())->exists())->toBeTrue();
});

it('RCH-006 test 2: the last attempt is not exhausted by a duplicate while it is in flight', function (): void {
    // One attempt only, which is where the false parking happens: the
    // duplicate's reservation is refused for lack of budget, and refusal used
    // to be read as exhaustion.
    config(['cloudbot.server_actions.max_attempts' => 1]);

    $scripted = Simulator::script();
    $action = reservationAction(ServerActionType::Reboot, 'in-flight-last-attempt');

    expect($this->actions->reserveAttempt($action, 1))->toBeTrue()
        ->and($action->fresh()->attempts)->toBe(1);

    app()->call([new ExecuteServerActionJob((int) $action->getKey()), 'handle']);

    $after = $action->fresh();

    expect($scripted->callCount('reboot'))->toBe(0)
        ->and($after->attempts)->toBe(1)
        // Not parked. The duplicate knows only that it could not reserve, and
        // that is not the same fact as "this operation is over".
        ->and($after->status)->toBe(ServerActionStatus::Pending)
        ->and($after->status)->not->toBe(ServerActionStatus::NeedsAttention)
        ->and($after->error_category)->toBeNull()
        ->and($after->settled_at)->toBeNull();

    // Worker A's call comes back confirmed, and settles through the ordinary
    // domain path — the same compare-and-set `succeed()` uses.
    expect($this->actions->settle(
        $after, ServerActionStatus::Succeeded, providerActionId: 'act-reboot-1',
    ))->toBeTrue();

    $settled = $action->fresh();

    expect($settled->status)->toBe(ServerActionStatus::Succeeded)
        ->and($settled->provider_action_id)->toBe('act-reboot-1')
        ->and($settled->attempts)->toBe(1);

    // Exactly once: a second settlement of the same outcome finds it closed.
    expect($this->actions->settle($settled, ServerActionStatus::Succeeded))->toBeFalse();
});

it('RCH-006 test 3: a known-safe retry still works, and only once', function (): void {
    $scripted = Simulator::script();
    $action = reservationAction(ServerActionType::PowerOff, 'in-flight-safe-retry');

    // Attempt one is rate limited: the provider refused it in a way that takes
    // no effect, and says so.
    $scripted->rejectOperation('powerOff', ProviderErrorCategory::RateLimited);
    app()->call([new ExecuteServerActionJob((int) $action->getKey()), 'handle']);

    $refused = $action->fresh();

    expect($scripted->callCount('powerOff'))->toBe(1)
        ->and($refused->attempts)->toBe(1)
        ->and($refused->status)->toBe(ServerActionStatus::Pending)
        ->and($refused->error_category)->toBe(ProviderErrorCategory::RateLimited)
        ->and($refused->retry_after)->not->toBeNull()
        ->and($this->actions->lastCallIsKnownSafe($refused))->toBeTrue();

    // Before the deadline a duplicate does nothing, evidence or not.
    app()->call([new ExecuteServerActionJob((int) $action->getKey()), 'handle']);

    expect($scripted->callCount('powerOff'))->toBe(1)
        ->and($action->fresh()->attempts)->toBe(1);

    // Due. Exactly one new attempt may be reserved, and reserving it clears the
    // evidence in the same statement.
    ServerAction::query()->whereKey($action->getKey())->update(['retry_after' => now()->subMinute()]);

    expect($this->actions->reserveAttempt($action->fresh(), attemptBudget()))->toBeTrue();

    $second = $action->fresh();

    expect($second->attempts)->toBe(2)
        ->and($second->error_category)->toBeNull()
        ->and($second->retry_after)->toBeNull();

    // A third duplicate, while attempt two is unresolved. It has budget (the
    // default is three) and no barrier — and it still may not call.
    expect($second->attempts)->toBeLessThan(attemptBudget());

    app()->call([new ExecuteServerActionJob((int) $action->getKey()), 'handle']);

    expect($scripted->callCount('powerOff'))->toBe(1)
        ->and($action->fresh()->attempts)->toBe(2)
        ->and($action->fresh()->status)->toBe(ServerActionStatus::Pending);

    // Attempt two comes back confirmed.
    expect($this->actions->settle(
        $action->fresh(), ServerActionStatus::Succeeded, providerActionId: 'act-power-2',
    ))->toBeTrue();

    $final = $action->fresh();

    expect($final->status)->toBe(ServerActionStatus::Succeeded)
        // Two provider WRITE attempts were reserved across the whole story, and
        // never a third.
        ->and($final->attempts)->toBe(2);
});

it('RCH-006 test 4: PostgreSQL refuses the second reservation, not PHP and not Redis', function (): void {
    $action = reservationAction(ServerActionType::Delete, 'in-flight-db-authority');

    expect($this->actions->reserveAttempt($action, attemptBudget()))->toBeTrue();

    $reserved = $action->fresh();

    // Everything the old predicate asked for is still true.
    expect($reserved->status)->toBe(ServerActionStatus::Pending)
        ->and($reserved->provider_action_id)->toBeNull()
        ->and($reserved->attempts)->toBeLessThan(attemptBudget())
        ->and($reserved->retry_after)->toBeNull()
        ->and($reserved->mayAttemptNow())->toBeTrue();

    // And the reservation is refused anyway, by the statement itself. No lock
    // was taken, no executor ran, no cache was consulted.
    expect($this->actions->reserveAttempt($reserved, attemptBudget()))->toBeFalse()
        ->and($action->fresh()->attempts)->toBe(1);

    // Passing the stale pre-reservation model changes nothing: the predicate is
    // evaluated against the row, not against the object.
    expect($this->actions->reserveAttempt($action, attemptBudget()))->toBeFalse()
        ->and($action->fresh()->attempts)->toBe(1);

    // Once a known-safe outcome is recorded, the same row becomes reservable
    // again — the rule is about evidence, not about a counter.
    $this->actions->postpone($action->fresh(), 1, ProviderErrorCategory::TransientProviderError);
    ServerAction::query()->whereKey($action->getKey())->update(['retry_after' => now()->subMinute()]);

    expect($this->actions->reserveAttempt($action->fresh(), attemptBudget()))->toBeTrue()
        ->and($action->fresh()->attempts)->toBe(2);
});

it('RCH-006: the database predicate is derived from the enum, not copied from it', function (): void {
    // If a category stops being retryable, the reservation must stop accepting
    // it in the same edit. A literal list in the WHERE clause is a copy that
    // nothing keeps in step.
    $declared = ProviderErrorCategory::retryableValues();

    $expected = array_values(array_map(
        static fn (ProviderErrorCategory $case): string => $case->value,
        array_filter(
            ProviderErrorCategory::cases(),
            static fn (ProviderErrorCategory $case): bool => $case->isRetryable(),
        ),
    ));

    expect($declared)->toBe($expected)
        ->and($declared)->not->toBeEmpty();

    foreach (ProviderErrorCategory::cases() as $case) {
        expect(in_array($case->value, $declared, true))->toBe($case->isRetryable());
    }

    // The categories that must never authorize a repeat.
    foreach ([
        ProviderErrorCategory::Timeout,
        ProviderErrorCategory::UncertainResult,
        ProviderErrorCategory::LocalPersistenceError,
        ProviderErrorCategory::InvalidRequest,
        ProviderErrorCategory::Authentication,
        ProviderErrorCategory::Authorization,
        ProviderErrorCategory::OutOfStock,
        ProviderErrorCategory::InsufficientProviderBalance,
    ] as $case) {
        expect($declared)->not->toContain($case->value);
    }
});
