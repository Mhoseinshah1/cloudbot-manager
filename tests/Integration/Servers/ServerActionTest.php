<?php

declare(strict_types=1);

use App\Audit\AuditEvent;
use App\Authorization\RoleProvisioner;
use App\Cloud\Enums\ProviderErrorCategory;
use App\Cloud\Enums\ProviderPowerState;
use App\Cloud\Fake\Models\FakeProviderAction;
use App\Cloud\Fake\Models\FakeProviderServer;
use App\Enums\ServerActionStatus;
use App\Enums\ServerActionType;
use App\Enums\ServerPowerState;
use App\Jobs\ExecuteServerActionJob;
use App\Models\AuditLog;
use App\Models\Server;
use App\Models\ServerAction;
use App\Models\User;
use App\Outbox\OutboxTopic;
use App\Provisioning\ProvisioningService;
use App\Servers\Exceptions\ServerActionNotAllowed;
use App\Servers\Exceptions\ServerActionRefusal;
use App\Servers\ServerActionService;
use App\Support\Queues;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Support\Provisioning\ProvisioningFloor;
use Tests\Support\Provisioning\Simulator;

/**
 * Power, reboot and delete, against the real simulated provider.
 *
 * FakeProvider keeps its state in PostgreSQL and records every action it is
 * asked to perform, so "one remote operation" is a row count rather than a mock
 * expectation. That distinction is the whole point of these tests: a duplicate
 * request must not become a duplicate reboot, and only something that actually
 * counts remote operations can show it did not.
 */
beforeEach(function (): void {
    app(RoleProvisioner::class)->sync();

    $this->floor = ProvisioningFloor::open();
    $this->actions = app(ServerActionService::class);

    app(ProvisioningService::class)->provision($this->floor->paidOrder());

    $this->server = Server::query()->sole();
    $this->customer = $this->floor->customer;
});

function requestAction(User $customer, Server $server, ServerActionType $action, string $key): ServerAction
{
    return app(ServerActionService::class)->request($customer, $server->getKey(), $action, $key);
}

function runAction(ServerAction $action): void
{
    app()->call([new ExecuteServerActionJob((int) $action->getKey()), 'handle']);
}

function remoteServer(Server $server): FakeProviderServer
{
    return FakeProviderServer::query()->where('provider_server_id', $server->provider_server_id)->sole();
}

it('powers a server off', function (): void {
    $action = requestAction($this->customer, $this->server, ServerActionType::PowerOff, 'k-off');

    // Nothing has reached the provider yet: the request is recorded and queued.
    expect($action->status)->toBe(ServerActionStatus::Pending)
        ->and(FakeProviderAction::query()->count())->toBe(0);

    runAction($action);

    expect($action->fresh()->status)->toBe(ServerActionStatus::Succeeded)
        ->and(remoteServer($this->server)->power_state)->toBe(ProviderPowerState::Off)
        ->and($this->server->fresh()->power_state)->toBe(ServerPowerState::Off)
        ->and(AuditLog::query()->where('event', AuditEvent::ServerPowerOff)->count())->toBe(1);
});

it('powers a server on', function (): void {
    remoteServer($this->server)->forceFill(['power_state' => ProviderPowerState::Off])->save();

    $action = requestAction($this->customer, $this->server, ServerActionType::PowerOn, 'k-on');
    runAction($action);

    expect($action->fresh()->status)->toBe(ServerActionStatus::Succeeded)
        ->and(remoteServer($this->server)->power_state)->toBe(ProviderPowerState::On)
        ->and(AuditLog::query()->where('event', AuditEvent::ServerPowerOn)->count())->toBe(1);
});

it('reboots a server', function (): void {
    $action = requestAction($this->customer, $this->server, ServerActionType::Reboot, 'k-reboot');
    runAction($action);

    expect($action->fresh()->status)->toBe(ServerActionStatus::Succeeded)
        ->and(FakeProviderAction::query()->where('command', 'reboot')->count())->toBe(1)
        ->and(AuditLog::query()->where('event', AuditEvent::ServerReboot)->count())->toBe(1);
});

it('performs one remote operation however many jobs arrive', function (): void {
    $action = requestAction($this->customer, $this->server, ServerActionType::Reboot, 'k-once');

    runAction($action);
    runAction($action);
    runAction($action);

    // The action settled on the first run; the rest found it settled and did
    // nothing. One reboot, not three.
    expect(FakeProviderAction::query()->where('command', 'reboot')->count())->toBe(1)
        ->and(AuditLog::query()->where('event', AuditEvent::ServerReboot)->count())->toBe(1);
});

it('turns a repeated request into one action', function (): void {
    $first = requestAction($this->customer, $this->server, ServerActionType::Reboot, 'k-dup');
    $second = requestAction($this->customer, $this->server, ServerActionType::Reboot, 'k-dup');

    expect($second->getKey())->toBe($first->getKey())
        ->and(ServerAction::query()->count())->toBe(1);

    runAction($first);
    runAction($second);

    expect(FakeProviderAction::query()->where('command', 'reboot')->count())->toBe(1);
});

it('refuses a key that already means something else', function (): void {
    requestAction($this->customer, $this->server, ServerActionType::Reboot, 'k-collide');

    // Answering with the existing action would tell a caller their delete
    // succeeded when what exists is somebody's reboot.
    expect(fn () => requestAction($this->customer, $this->server, ServerActionType::Delete, 'k-collide'))
        ->toThrow(ServerActionNotAllowed::class);
});

it('writes an intent to perform the work, on the provisioning queue', function (): void {
    $action = requestAction($this->customer, $this->server, ServerActionType::PowerOff, 'k-outbox');

    $message = App\Models\OutboxMessage::query()
        ->where('topic', OutboxTopic::ServerActionRequested)
        ->sole();

    expect($message->deduplication_key)->toBe(ServerActionService::requestKey($action))
        ->and($message->payload['server_action_id'])->toBe($action->getKey())
        // Never the interactive queue: a delete can block for as long as a
        // create can.
        ->and(ExecuteServerActionJob::queueName())->toBe(Queues::Provisioning->value);
});

it('refuses an action the provider does not implement', function (): void {
    // An adapter declines a capability by simply not implementing the
    // interface. No button appears for it, and a request naming it anyway is
    // refused before anything is recorded.
    Simulator::coreOnly();

    expect(app(App\Servers\ServerAccess::class)->capabilities($this->server))->toBe([]);

    try {
        requestAction($this->customer, $this->server, ServerActionType::Reboot, 'k-unsupported');
        $this->fail('The action was not refused.');
    } catch (ServerActionNotAllowed $refused) {
        expect($refused->refusal)->toBe(ServerActionRefusal::CapabilityUnsupported);
    }

    expect(ServerAction::query()->count())->toBe(0)
        ->and(FakeProviderAction::query()->count())->toBe(0);
});

it('refuses an action for a provider an operator switched off', function (): void {
    $this->floor->provider->forceFill(['enabled' => false])->save();

    expect(fn () => requestAction($this->customer, $this->server->fresh(), ServerActionType::Reboot, 'k-disabled'))
        ->toThrow(ServerActionNotAllowed::class);

    expect(ServerAction::query()->count())->toBe(0);
});

it('refuses a suspended customer', function (): void {
    $this->customer->forceFill(['status' => 'suspended'])->save();

    try {
        requestAction($this->customer->fresh(), $this->server, ServerActionType::Reboot, 'k-suspended');
        $this->fail('The action was not refused.');
    } catch (ServerActionNotAllowed $refused) {
        expect($refused->refusal)->toBe(ServerActionRefusal::InactiveCustomer);
    }

    expect(ServerAction::query()->count())->toBe(0)
        ->and(FakeProviderAction::query()->count())->toBe(0);
});

it('stores a normalized category, never the provider\'s own words', function (): void {
    $marker = 'SYNTHETIC-'.bin2hex(random_bytes(6));

    // The remote machine is gone, so the provider refuses with its own message.
    remoteServer($this->server)->forceFill(['provider_server_id' => 'moved-'.$marker])->save();

    $action = requestAction($this->customer, $this->server, ServerActionType::Reboot, 'k-error');
    runAction($action);

    $fresh = $action->fresh();

    expect($fresh->status)->not->toBe(ServerActionStatus::Succeeded)
        ->and($fresh->error_category)->toBeInstanceOf(ProviderErrorCategory::class);

    // Nothing the provider said is copied anywhere: its message quotes back the
    // request, and the request carries credentials.
    $stored = json_encode([
        ServerAction::query()->get()->toArray(),
        AuditLog::query()->get(['before', 'after', 'metadata'])->toArray(),
    ], JSON_THROW_ON_ERROR);

    expect($stored)->not->toContain($marker);
});

it('never sends a second reboot when the first one is uncertain', function (): void {
    $action = requestAction($this->customer, $this->server, ServerActionType::Reboot, 'k-uncertain');

    // The provider timed out. The machine may have rebooted, or may not have —
    // and a running server looks identical either way.
    Simulator::script()->loseResponseFor('reboot');

    runAction($action);

    expect($action->fresh()->status)->toBe(ServerActionStatus::NeedsAttention)
        ->and(FakeProviderAction::query()->where('command', 'reboot')->count())->toBe(0);

    // And a later worker does not decide for itself that it is safe to repeat.
    runAction($action->fresh());

    expect(FakeProviderAction::query()->where('command', 'reboot')->count())->toBe(0);
});

it('settles an uncertain power request from the machine\'s own state', function (): void {
    remoteServer($this->server)->forceFill(['power_state' => ProviderPowerState::Off])->save();

    $action = requestAction($this->customer, $this->server, ServerActionType::PowerOff, 'k-already-off');

    Simulator::script()->loseResponseFor('powerOff');

    runAction($action);

    // Unlike a reboot, "off" is exactly what was asked for. Whether this
    // request or an earlier one got it there does not matter.
    expect($action->fresh()->status)->toBe(ServerActionStatus::Succeeded);
});

it('stops asking after the configured number of attempts', function (): void {
    config()->set('cloudbot.server_actions.max_attempts', 2);

    $action = requestAction($this->customer, $this->server, ServerActionType::Reboot, 'k-attempts');

    Simulator::script()->loseResponseFor('reboot');
    runAction($action);

    // Parked rather than failed: an uncertain request may already have run.
    expect($action->fresh()->status)->toBe(ServerActionStatus::NeedsAttention)
        ->and($action->fresh()->attempts)->toBe(1);
});

it('keeps action history when a server is asked about again', function (): void {
    requestAction($this->customer, $this->server, ServerActionType::PowerOff, 'k-h1');
    requestAction($this->customer, $this->server, ServerActionType::PowerOn, 'k-h2');

    expect(ServerAction::query()->where('server_id', $this->server->getKey())->count())->toBe(2);
});

it('refuses to delete its own history', function (): void {
    $action = requestAction($this->customer, $this->server, ServerActionType::PowerOff, 'k-retained');

    expect(fn () => $action->delete())
        ->toThrow(App\Exceptions\FinancialRecordDeletionForbidden::class);

    // And at a psql prompt, which is where an accidental DELETE with a wrong
    // WHERE clause actually happens. Wrapped in a savepoint, because the
    // trigger aborts the transaction it fires in.
    expect(fn () => DB::transaction(fn () => DB::table('server_actions')->where('id', $action->getKey())->delete()))
        ->toThrow(QueryException::class);

    expect(fn () => DB::transaction(fn () => DB::table('server_actions')->delete()))
        ->toThrow(QueryException::class);

    expect(ServerAction::query()->count())->toBe(1);
});

it('refuses to rewrite whose action it was', function (string $attribute, mixed $value): void {
    $action = requestAction($this->customer, $this->server, ServerActionType::PowerOff, 'k-immutable');

    expect(fn () => $action->forceFill([$attribute => $value])->save())
        ->toThrow(App\Servers\Exceptions\ServerActionIsImmutable::class);

    // The database refuses it too, in a savepoint so the trigger's abort does
    // not take the rest of the test with it.
    expect(fn () => DB::transaction(
        fn () => DB::table('server_actions')->where('id', $action->getKey())->update([$attribute => $value]),
    ))->toThrow(QueryException::class);
})->with([
    'the action itself' => ['action', 'delete'],
    'the actor' => ['actor_id', 999_999],
    'the actor kind' => ['actor_type', 'system'],
    'the idempotency key' => ['idempotency_key', 'somebody-elses'],
]);

it('lets the lifecycle move while identity stays put', function (): void {
    $action = requestAction($this->customer, $this->server, ServerActionType::PowerOff, 'k-lifecycle');

    app(ServerActionService::class)->settle($action, ServerActionStatus::Running, 'provider-action-1');

    expect($action->fresh()->status)->toBe(ServerActionStatus::Running)
        ->and($action->fresh()->provider_action_id)->toBe('provider-action-1')
        ->and($action->fresh()->settled_at)->toBeNull();

    app(ServerActionService::class)->settle($action->fresh(), ServerActionStatus::Succeeded);

    expect($action->fresh()->status)->toBe(ServerActionStatus::Succeeded)
        ->and($action->fresh()->settled_at)->not->toBeNull();
});

it('lets only one worker settle an action', function (): void {
    $action = requestAction($this->customer, $this->server, ServerActionType::PowerOff, 'k-cas');
    $service = app(ServerActionService::class);

    expect($service->settle($action, ServerActionStatus::Succeeded))->toBeTrue()
        // Compare-and-set: the second caller learns it was not the one.
        ->and($service->settle($action->fresh(), ServerActionStatus::Failed))->toBeFalse()
        ->and($action->fresh()->status)->toBe(ServerActionStatus::Succeeded);
});
