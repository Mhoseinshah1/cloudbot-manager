<?php

declare(strict_types=1);

use App\Audit\AuditEvent;
use App\Authorization\RoleProvisioner;
use App\Cloud\Enums\ProviderServerStatus;
use App\Cloud\Fake\Models\FakeProviderAction;
use App\Cloud\Fake\Models\FakeProviderServer;
use App\Enums\ServerActionStatus;
use App\Enums\ServerActionType;
use App\Enums\ServerStatus;
use App\Enums\SubscriptionStatus;
use App\Jobs\ExecuteServerActionJob;
use App\Models\AuditLog;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\OutboxMessage;
use App\Models\Server;
use App\Models\ServerAction;
use App\Models\Subscription;
use App\Models\WalletTransaction;
use App\Outbox\OutboxTopic;
use App\Provisioning\ProvisioningService;
use App\Servers\ServerActionReconciler;
use App\Servers\ServerActionService;
use Illuminate\Support\Facades\DB;
use Tests\Support\Provisioning\ProvisioningFloor;
use Tests\Support\Provisioning\Simulator;

/**
 * A customer deleting their own server.
 *
 * The destructive path, so the tests are about what must not happen: no second
 * delete, no refund, no local termination on a hopeful assumption, and no
 * repeated hammering of a delete endpoint when nobody can say what happened.
 *
 * Release 1.0's money policy is deliberate and tested rather than assumed. A
 * customer who deletes a monthly server early gets no prorated refund — their
 * service ends, their history stays, and RefundService is not called at all.
 */
beforeEach(function (): void {
    app(RoleProvisioner::class)->sync();

    $this->floor = ProvisioningFloor::open();
    app(ProvisioningService::class)->provision($this->floor->paidOrder());

    $this->server = Server::query()->sole();
    $this->customer = $this->floor->customer;
    $this->actions = app(ServerActionService::class);
});

function requestDelete(string $key = 'k-delete'): ServerAction
{
    return app(ServerActionService::class)->request(
        test()->customer,
        test()->server->getKey(),
        ServerActionType::Delete,
        $key,
    );
}

it('deletes the remote machine and ends the service', function (): void {
    $balance = $this->customer->fresh()->wallet_balance_toman;

    $action = requestDelete();

    // Nothing has happened remotely yet: the request is recorded and queued.
    expect(FakeProviderAction::query()->where('command', 'delete')->count())->toBe(0);

    app()->call([new ExecuteServerActionJob((int) $action->getKey()), 'handle']);

    $server = $this->server->fresh();
    $subscription = Subscription::query()->sole();

    expect($action->fresh()->status)->toBe(ServerActionStatus::Succeeded)
        ->and(FakeProviderServer::query()->sole()->status)->toBe(ProviderServerStatus::Deleted)
        ->and($server->status)->toBe(ServerStatus::Terminated)
        ->and($server->terminated_at)->not->toBeNull()
        ->and($subscription->status)->toBe(SubscriptionStatus::Cancelled)
        ->and($subscription->cancelled_at)->not->toBeNull()
        // Entitlement ends at the deletion, not at the end of a month nobody
        // will use.
        ->and($subscription->current_period_end->timestamp)
        ->toBeLessThanOrEqual($server->terminated_at->timestamp + 1)
        // No prorated refund. Release 1.0 policy, and the balance proves it.
        ->and($this->customer->fresh()->wallet_balance_toman)->toBe($balance);
});

it('refunds nothing and calls no refund service', function (): void {
    $balance = $this->customer->fresh()->wallet_balance_toman;
    $ledgerBefore = WalletTransaction::query()->count();

    $action = requestDelete();
    app()->call([new ExecuteServerActionJob((int) $action->getKey()), 'handle']);

    expect($this->customer->fresh()->wallet_balance_toman)->toBe($balance)
        // Not one new ledger row of any kind: no refund, no credit, no
        // adjustment.
        ->and(WalletTransaction::query()->count())->toBe($ledgerBefore)
        // The only credit in the ledger is the top-up that funded the
        // purchase, from before any of this; the deletion added nothing.
        ->and(WalletTransaction::query()->where('type', 'refund')->count())->toBe(0)
        ->and(AuditLog::query()->where('event', AuditEvent::OrderRefunded)->count())->toBe(0)
        ->and(Order::query()->sole()->status->value)->not->toBe('refunded');
});

it('keeps every record the deletion was built on', function (): void {
    $action = requestDelete();
    app()->call([new ExecuteServerActionJob((int) $action->getKey()), 'handle']);

    // The machine is gone; the account of it is not.
    expect(Server::query()->count())->toBe(1)
        ->and(Order::query()->count())->toBe(1)
        ->and(Invoice::query()->count())->toBe(1)
        ->and(Subscription::query()->count())->toBe(1)
        ->and(ServerAction::query()->count())->toBe(1);
});

it('audits the request and the termination separately', function (): void {
    $action = requestDelete();

    // Asked for. This is the first thing anybody looks for when a machine is
    // still running and nobody admits deleting it.
    expect(AuditLog::query()->where('event', AuditEvent::ServerDeleteRequested)->count())->toBe(1)
        ->and(AuditLog::query()->where('event', AuditEvent::ServerTerminated)->count())->toBe(0);

    app()->call([new ExecuteServerActionJob((int) $action->getKey()), 'handle']);

    expect(AuditLog::query()->where('event', AuditEvent::ServerTerminated)->count())->toBe(1);

    // And the audit says plainly that no money went back, so an investigation
    // finds the policy recorded rather than inferred from nothing being there.
    $entry = AuditLog::query()->where('event', AuditEvent::ServerTerminated)->sole();

    expect($entry->metadata['refunded'])->toBeFalse();
});

it('tells the customer once', function (): void {
    $action = requestDelete();
    app()->call([new ExecuteServerActionJob((int) $action->getKey()), 'handle']);

    expect(OutboxMessage::query()->where('topic', OutboxTopic::ServerTerminated)->count())->toBe(1);

    // A second worker arriving late must not send a second farewell.
    app()->call([new ExecuteServerActionJob((int) $action->getKey()), 'handle']);

    expect(OutboxMessage::query()->where('topic', OutboxTopic::ServerTerminated)->count())->toBe(1);
});

it('sends one delete however many jobs arrive', function (): void {
    $action = requestDelete();

    app()->call([new ExecuteServerActionJob((int) $action->getKey()), 'handle']);
    app()->call([new ExecuteServerActionJob((int) $action->getKey()), 'handle']);
    app()->call([new ExecuteServerActionJob((int) $action->getKey()), 'handle']);

    expect(FakeProviderAction::query()->where('command', 'delete')->count())->toBe(1)
        ->and(AuditLog::query()->where('event', AuditEvent::ServerTerminated)->count())->toBe(1);
});

it('finishes a deletion whose local transaction failed', function (): void {
    // The case the specification names: the provider deleted it, and the local
    // write did not land. Reproduced by deleting remotely for real and leaving
    // the action open, which is exactly the state a crash would leave.
    $action = requestDelete();

    app(App\Cloud\ProviderManager::class)
        ->for($this->floor->provider)
        ->deleteServer($this->server->provider_server_id);

    expect($this->server->fresh()->status)->toBe(ServerStatus::Active)
        ->and($action->fresh()->status)->toBe(ServerActionStatus::Pending);

    // Later, the reconciler asks what actually happened.
    app(ServerActionReconciler::class)->reconcile($action->fresh());

    expect($this->server->fresh()->status)->toBe(ServerStatus::Terminated)
        ->and(Subscription::query()->sole()->status)->toBe(SubscriptionStatus::Cancelled)
        ->and($action->fresh()->status)->toBe(ServerActionStatus::Succeeded)
        // Still no refund, and still one delete at the provider.
        ->and(WalletTransaction::query()->where('type', 'refund')->count())->toBe(0)
        ->and(FakeProviderAction::query()->where('command', 'delete')->count())->toBe(1);
});

it('does not claim a deletion the provider could not confirm', function (): void {
    $action = requestDelete();

    // The delete request itself became unanswerable, and the machine is still
    // there.
    Simulator::script()->loseResponseFor('deleteServer');

    app()->call([new ExecuteServerActionJob((int) $action->getKey()), 'handle']);

    expect($this->server->fresh()->status)->toBe(ServerStatus::Active)
        ->and(Subscription::query()->sole()->status)->toBe(SubscriptionStatus::Active)
        ->and($action->fresh()->status)->not->toBe(ServerActionStatus::Succeeded);
});

it('never loops on a destructive endpoint', function (): void {
    config()->set('cloudbot.server_actions.max_attempts', 2);
    config()->set('cloudbot.server_actions.reconcile_after_seconds', 0);

    $action = requestDelete();

    // A provider that cannot be read at all: every attempt is ambiguous.
    Simulator::script()
        ->loseResponseFor('deleteServer')
        ->rejectOperation('getServer', App\Cloud\Enums\ProviderErrorCategory::Timeout);

    app()->call([new ExecuteServerActionJob((int) $action->getKey()), 'handle']);

    for ($i = 0; $i < 5; $i++) {
        app(ServerActionReconciler::class)->sweep();
    }

    // Bounded by the durable attempt count, not by hope. Parked for a person
    // rather than retried forever, and the machine was never deleted.
    expect($action->fresh()->attempts)->toBeLessThanOrEqual(2)
        ->and($action->fresh()->status)->toBe(ServerActionStatus::NeedsAttention)
        ->and(FakeProviderServer::query()->sole()->status)->not->toBe(ProviderServerStatus::Deleted)
        ->and($this->server->fresh()->status)->toBe(ServerStatus::Active);
});

it('finalizes a machine the provider says is already gone', function (): void {
    $action = requestDelete();

    // Somebody removed it at the provider. A delete request for a machine that
    // is not there has already achieved what the customer wanted.
    FakeProviderServer::query()->sole()->forceFill(['status' => ProviderServerStatus::Deleted])->save();

    app(ServerActionReconciler::class)->reconcile($action);

    expect($this->server->fresh()->status)->toBe(ServerStatus::Terminated)
        ->and($action->fresh()->status)->toBe(ServerActionStatus::Succeeded);
});

it('terminates a server exactly once under repeated finalization', function (): void {
    $action = requestDelete();
    app()->call([new ExecuteServerActionJob((int) $action->getKey()), 'handle']);

    $terminatedAt = $this->server->fresh()->terminated_at->toIso8601String();

    app(App\Servers\ServerTerminationService::class)->finalize($action->fresh());
    app(App\Servers\ServerTerminationService::class)->finalize($action->fresh());

    // A second pass must not restate when somebody's service ended.
    expect($this->server->fresh()->terminated_at->toIso8601String())->toBe($terminatedAt)
        ->and(AuditLog::query()->where('event', AuditEvent::ServerTerminated)->count())->toBe(1)
        ->and(OutboxMessage::query()->where('topic', OutboxTopic::ServerTerminated)->count())->toBe(1);
});

it('never extends a subscription that already ended', function (): void {
    // A server whose period is already over, deleted afterwards. Ending the
    // entitlement must not push it forward.
    $subscription = Subscription::query()->sole();
    $subscription->forceFill([
        'current_period_start' => now()->subDays(33),
        'current_period_end' => now()->subDays(3),
    ])->save();
    $endedAt = $subscription->fresh()->current_period_end->toIso8601String();

    $action = requestDelete();
    app()->call([new ExecuteServerActionJob((int) $action->getKey()), 'handle']);

    expect(Subscription::query()->sole()->current_period_end->toIso8601String())->toBe($endedAt)
        ->and(Subscription::query()->sole()->status)->toBe(SubscriptionStatus::Cancelled);
});

it('keeps subscription history rather than deleting it', function (): void {
    $action = requestDelete();
    app()->call([new ExecuteServerActionJob((int) $action->getKey()), 'handle']);

    expect(fn () => Subscription::query()->sole()->delete())
        ->toThrow(App\Exceptions\FinancialRecordDeletionForbidden::class);

    expect(fn () => DB::transaction(fn () => DB::table('subscriptions')->delete()))
        ->toThrow(Illuminate\Database\QueryException::class);
});
