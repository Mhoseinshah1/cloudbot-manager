<?php

declare(strict_types=1);

use App\Audit\AuditEvent;
use App\Authorization\RoleProvisioner;
use App\Cloud\Data\CreateServerRequest;
use App\Cloud\Fake\FakeCatalog;
use App\Cloud\Fake\Models\FakeProviderServer;
use App\Enums\ServerPowerState;
use App\Enums\ServerStatus;
use App\Enums\SubscriptionStatus;
use App\Models\AuditLog;
use App\Models\OutboxMessage;
use App\Models\Server;
use App\Models\Subscription;
use App\Outbox\OutboxTopic;
use App\Provisioning\InventoryReconciler;
use App\Provisioning\ProvisioningService;
use Illuminate\Support\Str;
use Tests\Support\Provisioning\ProvisioningFloor;
use Tests\Support\Provisioning\Simulator;

/**
 * Comparing what a provider actually holds against what we think we sold.
 *
 * A financial control. Every machine the provider holds is billed to us; every
 * machine we believe in is billed to a customer. When the two lists disagree,
 * somebody is paying for nothing, and the responses are deliberately
 * asymmetric: drift is corrected, a missing machine stops a subscription
 * renewing, and an unexplained machine is reported but never destroyed.
 */
beforeEach(function (): void {
    app(RoleProvisioner::class)->sync();

    $this->floor = ProvisioningFloor::open();
    $this->reconciler = app(InventoryReconciler::class);
});

function deliveredServer(): Server
{
    app(ProvisioningService::class)->provision(test()->floor->paidOrder());

    return Server::query()->firstOrFail();
}

it('reports a healthy estate as having nothing wrong', function (): void {
    deliveredServer();

    $report = $this->reconciler->reconcile($this->floor->provider);

    expect($report->succeeded())->toBeTrue()
        ->and($report->localChecked)->toBe(1)
        ->and($report->missing)->toBe(0)
        ->and($report->orphans)->toBe(0);
});

it('corrects an address and a power state without touching identity or money', function (): void {
    $server = deliveredServer();
    $before = $server->only(Server::IMMUTABLE);

    // The provider's answer moves on: a rebuilt machine gets a new address and
    // somebody powers it off.
    FakeProviderServer::query()->where('provider_server_id', $server->provider_server_id)
        ->update(['ipv4' => '203.0.113.77', 'power_state' => 'off']);

    $report = $this->reconciler->reconcile($this->floor->provider);
    $fresh = $server->fresh();

    expect($report->drifted)->toBe(1)
        ->and($fresh->ip_address)->toBe('203.0.113.77')
        ->and($fresh->power_state)->toBe(ServerPowerState::Off)
        // Everything a provider is not entitled to restate is untouched.
        ->and($fresh->only(Server::IMMUTABLE))->toBe($before)
        ->and(AuditLog::query()->where('event', AuditEvent::InventoryDriftCorrected)->count())->toBe(1);
});

it('stops a subscription renewing when the machine is gone', function (): void {
    $server = deliveredServer();

    Simulator::plain()->deleteServer($server->provider_server_id);

    $report = $this->reconciler->reconcile($this->floor->provider);
    $fresh = $server->fresh();

    expect($report->missing)->toBe(1)
        ->and($fresh->status)->toBe(ServerStatus::Missing)
        // Phase 11 must never charge again for a machine nobody can find.
        ->and($fresh->subscription->status)->toBe(SubscriptionStatus::NeedsAttention)
        ->and($fresh->subscription->status->isRenewable())->toBeFalse()
        // The history stays. A missing server is not a deleted record.
        ->and(Server::query()->count())->toBe(1)
        ->and(Subscription::query()->count())->toBe(1)
        ->and(AuditLog::query()->where('event', AuditEvent::InventoryRemoteMissing)->count())->toBe(1);

    $alert = OutboxMessage::query()->where('topic', OutboxTopic::InventoryDiscrepancy)->firstOrFail();

    expect($alert->payload['kind'])->toBe('remote_missing')
        ->and($alert->payload['server_id'])->toBe($server->getKey());
});

it('never refunds a customer merely because a machine went missing', function (): void {
    $server = deliveredServer();
    $balance = $this->floor->customer->fresh()->wallet_balance_toman;

    Simulator::plain()->deleteServer($server->provider_server_id);
    $this->reconciler->reconcile($this->floor->provider);

    // Why the machine is gone decides whether anything is owed, and that is a
    // question a person answers.
    expect($this->floor->customer->fresh()->wallet_balance_toman)->toBe($balance)
        ->and($server->fresh()->order->fresh()->status->value)->toBe('provisioned');
});

it('reports an unexplained machine and never deletes it', function (): void {
    deliveredServer();

    // A machine at the provider that no local record explains: a create whose
    // local write was lost, or somebody's manual work.
    $scripted = Simulator::script();
    Simulator::plain()->createServer(new CreateServerRequest(
        provisioningToken: (string) Str::uuid(),
        providerPlanId: FakeCatalog::PLAN_SMALL,
        providerLocationId: FakeCatalog::LOCATION_PRIMARY,
        providerImageId: FakeCatalog::IMAGE_UBUNTU,
        name: 'cbm-unexplained',
    ));

    $report = $this->reconciler->reconcile($this->floor->provider);

    expect($report->orphans)->toBe(1)
        // Never. It may be a customer's machine whose local write failed, and
        // tidying the report would destroy their data.
        ->and($scripted->callCount('deleteServer'))->toBe(0)
        ->and(FakeProviderServer::query()->count())->toBe(2)
        ->and(AuditLog::query()->where('event', AuditEvent::InventoryOrphanDetected)->count())->toBe(1);

    $alert = OutboxMessage::query()
        ->where('topic', OutboxTopic::InventoryDiscrepancy)
        ->get()
        ->firstWhere(fn (OutboxMessage $m): bool => $m->payload['kind'] === 'orphan');

    expect($alert)->not->toBeNull()
        // Whether an operator can trace it back, stated rather than acted on.
        ->and($alert->payload['correlatable'])->toBeTrue()
        ->and($alert->payload['provisioning_uuid'])->not->toBeNull();
});

it('marks nothing missing when the inventory could not be read', function (): void {
    $server = deliveredServer();

    $scripted = Simulator::script();
    $scripted->onListServers(function (): never {
        throw App\Cloud\Exceptions\ProviderException::unavailable(
            App\Cloud\Fake\FakeProvider::CODE, 'The API is down.',
        );
    });

    $report = $this->reconciler->reconcile($this->floor->provider);

    expect($report->succeeded())->toBeFalse()
        ->and($report->missing)->toBe(0)
        // The one inference that must never be made: an unread inventory is not
        // an empty one. Made here, it would strand every customer at once.
        ->and($server->fresh()->status)->toBe(ServerStatus::Active)
        ->and($server->fresh()->subscription->status)->toBe(SubscriptionStatus::Active);

    // And the command says so rather than reporting a clean sweep.
    $this->artisan('providers:reconcile-inventory')->assertExitCode(1);
});

it('reconciles a disabled provider, because its bills do not stop', function (): void {
    $server = deliveredServer();
    $this->floor->provider->forceFill(['enabled' => false])->save();

    FakeProviderServer::query()->where('provider_server_id', $server->provider_server_id)
        ->update(['ipv4' => '203.0.113.5']);

    $report = $this->reconciler->reconcile($this->floor->provider->fresh());

    expect($report->succeeded())->toBeTrue()
        ->and($server->fresh()->ip_address)->toBe('203.0.113.5');
});

it('is safe to run twice', function (): void {
    $server = deliveredServer();
    Simulator::plain()->deleteServer($server->provider_server_id);

    $this->reconciler->reconcile($this->floor->provider);
    $second = $this->reconciler->reconcile($this->floor->provider);

    expect($second->missing)->toBe(1)
        ->and($server->fresh()->status)->toBe(ServerStatus::Missing)
        // One alert, not one per sweep. A daily sweeper must not produce a
        // daily message about the same gap.
        ->and(OutboxMessage::query()->where('topic', OutboxTopic::InventoryDiscrepancy)->count())->toBe(1)
        ->and(AuditLog::query()->where('event', AuditEvent::InventoryRemoteMissing)->count())->toBe(1);
});

it('brings a server back when the provider holds it again', function (): void {
    $server = deliveredServer();
    $remote = FakeProviderServer::query()->firstOrFail();

    Simulator::plain()->deleteServer($server->provider_server_id);
    $this->reconciler->reconcile($this->floor->provider);

    expect($server->fresh()->status)->toBe(ServerStatus::Missing);

    // The provider's listing recovers. Refreshed first: the in-memory model
    // still remembers the pre-delete status, so a forceFill would be a no-op.
    $remote->refresh();
    $remote->forceFill(['status' => 'active'])->save();
    $this->reconciler->reconcile($this->floor->provider);

    expect($server->fresh()->status)->toBe(ServerStatus::Active);
});

it('leaves terminated history out of the discrepancy count', function (): void {
    $server = deliveredServer();
    $server->forceFill(['status' => ServerStatus::Terminated, 'terminated_at' => now()])->save();

    Simulator::plain()->deleteServer($server->provider_server_id);

    $report = $this->reconciler->reconcile($this->floor->provider);

    // A provider no longer holding a terminated machine is the expected state.
    expect($report->missing)->toBe(0)
        ->and($report->localChecked)->toBe(0)
        ->and($server->fresh()->status)->toBe(ServerStatus::Terminated);
});

it('reports cleanly from the command when everything agrees', function (): void {
    deliveredServer();

    $this->artisan('providers:reconcile-inventory')->assertExitCode(0);
});
