<?php

declare(strict_types=1);

use App\Audit\AuditEvent;
use App\Authorization\RoleProvisioner;
use App\Cloud\Enums\ProviderErrorCategory;
use App\Cloud\Fake\Models\FakeProviderServer;
use App\Enums\OrderFailureCategory;
use App\Enums\OrderStatus;
use App\Enums\ProvisioningOutcome;
use App\Models\AuditLog;
use App\Models\OutboxMessage;
use App\Models\ProvisioningAttempt;
use App\Models\Server;
use App\Models\Subscription;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Outbox\OutboxTopic;
use App\Provisioning\Data\ProvisioningResult;
use App\Provisioning\ProvisioningService;
use App\Provisioning\ReconciliationService;
use Illuminate\Support\Facades\DB;
use Tests\Support\Provisioning\ProvisioningFloor;
use Tests\Support\Provisioning\Simulator;

/**
 * The paths where something goes wrong, which is where all the money is.
 *
 * The distinction every test here turns on: does a remote machine exist? A
 * confirmed no means the customer's money goes back. An unknown means it does
 * not — refunding a customer whose server was in fact built hands them a machine
 * for free, and the only honest response to "we do not know" is to find out.
 */
beforeEach(function (): void {
    app(RoleProvisioner::class)->sync();

    $this->floor = ProvisioningFloor::open();
    $this->provisioning = app(ProvisioningService::class);
});

function walletBalance(): int
{
    return (int) User::query()->whereKey(test()->floor->customer->id)->value('wallet_balance_toman');
}

function ledgerTotal(): int
{
    return (int) WalletTransaction::query()
        ->where('user_id', test()->floor->customer->id)
        ->sum('amount_toman');
}

it('refunds once and never calls create when availability is lost', function (): void {
    $order = $this->floor->paidOrder();
    $charged = walletBalance();

    $scripted = Simulator::script();
    $scripted->onAvailability(fn (): bool => false);

    $result = $this->provisioning->provision($order);

    expect($result->state)->toBe(ProvisioningResult::Refunded)
        // The point of checking availability first: no create is attempted at
        // all, so no machine can exist because of this order.
        ->and($scripted->callCount('createServer'))->toBe(0)
        ->and(FakeProviderServer::query()->count())->toBe(0)
        ->and(Server::query()->count())->toBe(0)
        ->and(Subscription::query()->count())->toBe(0);

    $fresh = $order->fresh();

    expect($fresh->status)->toBe(OrderStatus::Refunded)
        ->and($fresh->failure_category)->toBe(OrderFailureCategory::AvailabilityLostNoServer)
        ->and(walletBalance())->toBe($charged + $order->total_toman)
        // Exactly once, under the specification's key.
        ->and(WalletTransaction::query()->where('idempotency_key', $order->refundIdempotencyKey())->count())
        ->toBe(1)
        ->and(walletBalance())->toBe(ledgerTotal());

    // Attempted again, as a duplicated job would. No second refund.
    $this->provisioning->provision($order->fresh());

    expect(WalletTransaction::query()->where('idempotency_key', $order->refundIdempotencyKey())->count())
        ->toBe(1)
        ->and(walletBalance())->toBe(ledgerTotal());
});

it('refunds a confirmed provider rejection exactly once', function (): void {
    $order = $this->floor->paidOrder();
    $charged = walletBalance();

    $scripted = Simulator::script();
    $scripted->rejectCreate(ProviderErrorCategory::InvalidRequest, 'That plan cannot be built here.');

    $result = $this->provisioning->provision($order);

    expect($result->state)->toBe(ProvisioningResult::Refunded)
        ->and(FakeProviderServer::query()->count())->toBe(0)
        ->and(Server::query()->count())->toBe(0)
        ->and(Subscription::query()->count())->toBe(0)
        ->and($order->fresh()->status)->toBe(OrderStatus::Refunded)
        ->and($order->fresh()->failure_category)->toBe(OrderFailureCategory::ProviderRejectedNoServer)
        ->and(walletBalance())->toBe($charged + $order->total_toman)
        ->and(walletBalance())->toBe(ledgerTotal());

    $attempt = ProvisioningAttempt::query()->firstOrFail();

    expect($attempt->outcome)->toBe(ProvisioningOutcome::RejectedNoServer)
        // The normalized category, not the provider's words.
        ->and($attempt->error_category)->toBe(ProviderErrorCategory::InvalidRequest);

    // The customer's refund notice, and the operator's alert, are separate
    // messages for separate audiences.
    expect(OutboxMessage::query()->where('topic', OutboxTopic::OrderRefunded)->count())->toBe(1)
        ->and(OutboxMessage::query()->where('topic', OutboxTopic::ProvisioningFailed)->count())->toBe(1);
});

it('alerts an operator when the provider will not authenticate us', function (): void {
    $order = $this->floor->paidOrder();

    $scripted = Simulator::script();
    $scripted->rejectCreate(ProviderErrorCategory::Authentication, 'Credentials rejected.');

    $this->provisioning->provision($order);

    $alert = OutboxMessage::query()->where('topic', OutboxTopic::ProvisioningFailed)->firstOrFail();

    expect($alert->payload['error_category'])->toBe(ProviderErrorCategory::Authentication->value)
        ->and($alert->payload['order_number'])->toBe($order->order_number);

    // No retry could fix a credential problem, so this is a confirmed rejection
    // and the customer gets their money back while an operator fixes it.
    expect($order->fresh()->status)->toBe(OrderStatus::Refunded);
});

it('never refunds a create whose outcome is unknown', function (): void {
    // The single most important test in the phase. The provider genuinely
    // creates a machine and then the response is lost — which from here is
    // indistinguishable from a refusal.
    $order = $this->floor->paidOrder();
    $charged = walletBalance();

    $scripted = Simulator::script();
    $scripted->loseCreateResponse();

    $result = $this->provisioning->provision($order);

    expect($result->state)->toBe(ProvisioningResult::NeedsAttention)
        ->and($result->outcome)->toBe(ProvisioningOutcome::Uncertain);

    $fresh = $order->fresh();

    expect($fresh->status)->toBe(OrderStatus::NeedsAttention)
        ->and($fresh->status)->not->toBe(OrderStatus::Failed)
        ->and($fresh->failure_category)->toBe(OrderFailureCategory::UncertainResult)
        // The machine really is there.
        ->and(FakeProviderServer::query()->count())->toBe(1)
        // And the customer is still charged for it, because they have it.
        ->and(walletBalance())->toBe($charged)
        ->and(WalletTransaction::query()->where('idempotency_key', $order->refundIdempotencyKey())->count())
        ->toBe(0)
        ->and(walletBalance())->toBe(ledgerTotal());

    // An operator is told, because a person has to look.
    expect(OutboxMessage::query()->where('topic', OutboxTopic::ProvisioningNeedsAttention)->count())->toBe(1);
});

it('recovers an uncertain create by reconciling the same token', function (): void {
    $order = $this->floor->paidOrder();
    $charged = walletBalance();

    $scripted = Simulator::script();
    $scripted->loseCreateResponse();

    $this->provisioning->provision($order);
    $token = $order->fresh()->provisioning_uuid;

    // The provider stops misbehaving. Reconciliation looks up the same token.
    $scripted->afterCreate(fn ($server) => $server);

    $result = app(ReconciliationService::class)->reconcile($order->fresh());

    expect($result->state)->toBe(ProvisioningResult::Provisioned)
        ->and($result->outcome)->toBe(ProvisioningOutcome::RecoveredExisting)
        // Nothing was created a second time.
        ->and($scripted->callCount('createServer'))->toBe(1)
        ->and(FakeProviderServer::query()->count())->toBe(1)
        ->and(Server::query()->count())->toBe(1)
        ->and(Subscription::query()->count())->toBe(1)
        ->and($order->fresh()->status)->toBe(OrderStatus::Provisioned)
        // Same token throughout.
        ->and($order->fresh()->provisioning_uuid)->toBe($token)
        ->and(Server::query()->firstOrFail()->provisioning_uuid)->toBe($token)
        // Never refunded on the way.
        ->and(walletBalance())->toBe($charged)
        ->and(walletBalance())->toBe(ledgerTotal());
});

it('recovers a server whose local write failed, without building a second one', function (): void {
    // The other half of the same problem: the provider succeeded and the local
    // transaction did not. The machine exists; nothing local knows it.
    $order = $this->floor->paidOrder();
    $charged = walletBalance();

    $scripted = Simulator::script();

    // An objectively induced PostgreSQL failure, after the remote create. Not a
    // stub that returns an error before anything happened — that would prove
    // nothing, because there would be nothing to recover.
    DB::unprepared(<<<'SQL'
        CREATE OR REPLACE FUNCTION servers_induced_persistence_failure() RETURNS trigger AS $$
        BEGIN
            RAISE EXCEPTION 'induced local persistence failure';
        END;
        $$ LANGUAGE plpgsql;

        CREATE TRIGGER servers_induced_failure
            BEFORE INSERT ON servers
            FOR EACH ROW EXECUTE FUNCTION servers_induced_persistence_failure();
    SQL);

    $result = $this->provisioning->provision($order);

    expect($result->state)->toBe(ProvisioningResult::Retryable)
        ->and($result->outcome)->toBe(ProvisioningOutcome::RemoteCreatedLocalFailed)
        // The machine is there.
        ->and(FakeProviderServer::query()->count())->toBe(1)
        // Nothing local is.
        ->and(Server::query()->count())->toBe(0)
        ->and(Subscription::query()->count())->toBe(0)
        // And no money moved, because nothing is confirmed absent.
        ->and(walletBalance())->toBe($charged)
        ->and($order->fresh()->status)->toBe(OrderStatus::Provisioning);

    $token = $order->fresh()->provisioning_uuid;

    expect(ProvisioningAttempt::query()->firstOrFail()->outcome)
        ->toBe(ProvisioningOutcome::RemoteCreatedLocalFailed);

    // The database recovers.
    DB::unprepared('DROP TRIGGER servers_induced_failure ON servers');
    DB::unprepared('DROP FUNCTION servers_induced_persistence_failure()');

    $recovered = app(ReconciliationService::class)->reconcile($order->fresh());

    expect($recovered->state)->toBe(ProvisioningResult::Provisioned)
        ->and($scripted->callCount('createServer'))->toBe(1)
        ->and(FakeProviderServer::query()->count())->toBe(1)
        ->and(Server::query()->count())->toBe(1)
        ->and(Subscription::query()->count())->toBe(1)
        ->and($order->fresh()->status)->toBe(OrderStatus::Provisioned)
        ->and($order->fresh()->provisioning_uuid)->toBe($token)
        ->and(walletBalance())->toBe($charged)
        ->and(walletBalance())->toBe(ledgerTotal());
});

it('retries a transient failure without refunding or re-tokening', function (): void {
    $order = $this->floor->paidOrder();
    $charged = walletBalance();

    $scripted = Simulator::script();
    $scripted->rejectCreate(ProviderErrorCategory::RateLimited, 'Slow down.');

    $result = $this->provisioning->provision($order);

    expect($result->state)->toBe(ProvisioningResult::Retryable)
        ->and($order->fresh()->status)->toBe(OrderStatus::Provisioning)
        ->and(walletBalance())->toBe($charged)
        ->and(Server::query()->count())->toBe(0)
        ->and(ProvisioningAttempt::query()->firstOrFail()->outcome)
        ->toBe(ProvisioningOutcome::TransientFailure);
});

it('looks the token up before every create, so a live machine is adopted not duplicated', function (): void {
    $order = $this->floor->paidOrder();

    // Somebody already built this order's machine — a previous attempt whose
    // record was lost.
    $prepared = $this->provisioning->prepare($order);
    Simulator::plain()->createServer(new App\Cloud\Data\CreateServerRequest(
        provisioningToken: (string) $prepared->provisioning_uuid,
        providerPlanId: App\Cloud\Fake\FakeCatalog::PLAN_SMALL,
        providerLocationId: App\Cloud\Fake\FakeCatalog::LOCATION_PRIMARY,
        providerImageId: App\Cloud\Fake\FakeCatalog::IMAGE_UBUNTU,
        name: 'cbm-earlier-attempt',
    ));

    $scripted = Simulator::script();
    $result = $this->provisioning->provision($order->fresh());

    expect($result->state)->toBe(ProvisioningResult::Provisioned)
        ->and($result->outcome)->toBe(ProvisioningOutcome::RecoveredExisting)
        // The lookup happened and the create did not.
        ->and($scripted->callCount('createServer'))->toBe(0)
        ->and(FakeProviderServer::query()->count())->toBe(1)
        ->and(Server::query()->count())->toBe(1);
});

it('stops trying after the configured maximum and parks rather than refunding', function (): void {
    $order = $this->floor->paidOrder();
    $charged = walletBalance();

    $scripted = Simulator::script();
    $scripted->rejectCreate(ProviderErrorCategory::TransientProviderError, 'Still broken.');

    $max = (int) config('cloudbot.provisioning.max_attempts');

    for ($run = 0; $run < $max; $run++) {
        $result = $this->provisioning->provision($order->fresh());
    }

    expect(ProvisioningAttempt::query()->count())->toBe($max)
        ->and($result->state)->toBe(ProvisioningResult::NeedsAttention)
        ->and($order->fresh()->status)->toBe(OrderStatus::NeedsAttention)
        // Exhausting a counter is not evidence that no machine exists, so the
        // money stays where it is until somebody finds out.
        ->and(walletBalance())->toBe($charged)
        ->and(walletBalance())->toBe(ledgerTotal())
        ->and(AuditLog::query()->where('event', AuditEvent::OrderNeedsAttention)->count())->toBeGreaterThan(0);
});
