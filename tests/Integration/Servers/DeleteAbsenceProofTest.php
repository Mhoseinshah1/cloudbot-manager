<?php

declare(strict_types=1);

use App\Authorization\RoleProvisioner;
use App\Cloud\Enums\ProviderErrorCategory;
use App\Cloud\Enums\ProviderServerStatus;
use App\Cloud\Fake\Models\FakeProviderServer;
use App\Enums\ServerActionStatus;
use App\Enums\ServerActionType;
use App\Enums\ServerStatus;
use App\Enums\SubscriptionStatus;
use App\Models\Server;
use App\Models\ServerAction;
use App\Models\Subscription;
use App\Models\WalletTransaction;
use App\Provisioning\ProvisioningService;
use App\Servers\ServerActionReconciler;
use App\Servers\ServerActionService;
use Tests\Support\Provisioning\ProvisioningFloor;
use Tests\Support\Provisioning\Simulator;

/**
 * What may, and may not, end a customer's service.
 *
 * A deletion finalized locally is irreversible in every way that matters: the
 * server record is terminated, the subscription is cancelled, and the customer
 * stops being owed the service they paid for. So the only two answers that may
 * cause it are the provider affirmatively saying the machine is deleted, and
 * the provider affirmatively saying no such machine exists.
 *
 * Every other outcome is a failure to find out. A rejected credential, a
 * malformed request, a rate limit, a timeout — all of them happen constantly
 * while a customer's server runs perfectly well, and every one of them is
 * tested here to prove it changes nothing.
 */
beforeEach(function (): void {
    app(RoleProvisioner::class)->sync();

    $this->floor = ProvisioningFloor::open();
    app(ProvisioningService::class)->provision($this->floor->paidOrder());

    $this->server = Server::query()->sole();

    $this->action = app(ServerActionService::class)->request(
        $this->floor->customer,
        $this->server->getKey(),
        ServerActionType::Delete,
        'absence-proof',
    );
});

it('never terminates when the lookup merely failed', function (ProviderErrorCategory $category): void {
    // The delete request is unanswerable, and then the lookup that would settle
    // it fails too. Nobody knows anything, so nothing is claimed.
    Simulator::script()
        ->loseResponseFor('deleteServer')
        ->rejectOperation('getServer', $category);

    app(ServerActionReconciler::class)->reconcile($this->action);

    expect($this->server->fresh()->status)->toBe(ServerStatus::Active)
        ->and($this->server->fresh()->terminated_at)->toBeNull()
        ->and(Subscription::query()->sole()->status)->toBe(SubscriptionStatus::Active)
        ->and($this->action->fresh()->status)->not->toBe(ServerActionStatus::Succeeded)
        // And nothing paid anybody back on the way past.
        ->and(WalletTransaction::query()->where('type', 'refund')->count())->toBe(0);
})->with([
    // The one that used to terminate a customer's server. An invalid request
    // means we sent something wrong — a malformed id, an unsupported
    // parameter, an adapter that built a bad call — and none of that is
    // evidence about whether a machine exists.
    'a request we got wrong' => [ProviderErrorCategory::InvalidRequest],
    'a rejected credential' => [ProviderErrorCategory::Authentication],
    'a permission we lack' => [ProviderErrorCategory::Authorization],
    'a provider that is down' => [ProviderErrorCategory::Unavailable],
    'a rate limit' => [ProviderErrorCategory::RateLimited],
    'a transient fault' => [ProviderErrorCategory::TransientProviderError],
    'a timeout' => [ProviderErrorCategory::Timeout],
    'an outcome nobody knows' => [ProviderErrorCategory::UncertainResult],
]);

it('terminates when the provider confirms the machine is deleted', function (): void {
    FakeProviderServer::query()->sole()->forceFill(['status' => ProviderServerStatus::Deleted])->save();

    app(ServerActionReconciler::class)->reconcile($this->action);

    expect($this->server->fresh()->status)->toBe(ServerStatus::Terminated)
        ->and(Subscription::query()->sole()->status)->toBe(SubscriptionStatus::Cancelled)
        ->and($this->action->fresh()->status)->toBe(ServerActionStatus::Succeeded)
        ->and(WalletTransaction::query()->where('type', 'refund')->count())->toBe(0);
});

it('terminates when the provider confirms there is no such machine', function (): void {
    // Absence, stated as an answer rather than inferred from a failure. The
    // row is removed outright, so the provider genuinely has never heard of
    // this identity — which is what a real provider's 404 means.
    FakeProviderServer::query()->sole()->forceDelete();

    app(ServerActionReconciler::class)->reconcile($this->action);

    expect($this->server->fresh()->status)->toBe(ServerStatus::Terminated)
        ->and(Subscription::query()->sole()->status)->toBe(SubscriptionStatus::Cancelled)
        ->and($this->action->fresh()->status)->toBe(ServerActionStatus::Succeeded)
        ->and(WalletTransaction::query()->where('type', 'refund')->count())->toBe(0);
});

it('terminates exactly once when absence is reported repeatedly', function (): void {
    FakeProviderServer::query()->sole()->forceDelete();

    $reconciler = app(ServerActionReconciler::class);

    $reconciler->reconcile($this->action);
    $terminatedAt = $this->server->fresh()->terminated_at->toIso8601String();

    $reconciler->reconcile($this->action->fresh());
    $reconciler->reconcile($this->action->fresh());

    expect($this->server->fresh()->terminated_at->toIso8601String())->toBe($terminatedAt)
        ->and(ServerAction::query()->count())->toBe(1)
        ->and(App\Models\AuditLog::query()->where('event', App\Audit\AuditEvent::ServerTerminated)->count())->toBe(1);
});

it('leaves a power action alone when the machine has vanished', function (): void {
    // A server that disappeared from under a live local record is inventory
    // drift, and the sweep whose job that is owns the correction. A power
    // action must not quietly succeed, and must not terminate anything either.
    $power = app(ServerActionService::class)->request(
        $this->floor->customer,
        $this->server->getKey(),
        ServerActionType::PowerOff,
        'absence-power',
    );

    FakeProviderServer::query()->sole()->forceDelete();

    app(ServerActionReconciler::class)->reconcile($power);

    expect($power->fresh()->status)->not->toBe(ServerActionStatus::Succeeded)
        ->and($this->server->fresh()->status)->toBe(ServerStatus::Active);
});
