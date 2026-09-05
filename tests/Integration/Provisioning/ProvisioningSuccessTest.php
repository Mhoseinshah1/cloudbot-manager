<?php

declare(strict_types=1);

use App\Audit\AuditEvent;
use App\Authorization\RoleProvisioner;
use App\Cloud\Fake\Models\FakeProviderServer;
use App\Enums\OrderStatus;
use App\Enums\ProvisioningOutcome;
use App\Enums\ServerPowerState;
use App\Enums\ServerStatus;
use App\Enums\SubscriptionStatus;
use App\Models\AuditLog;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\OutboxMessage;
use App\Models\ProvisioningAttempt;
use App\Models\Server;
use App\Models\Subscription;
use App\Outbox\OutboxTopic;
use App\Provisioning\Data\ProvisioningResult;
use App\Provisioning\ProvisioningService;
use Tests\Support\Provisioning\ProvisioningFloor;

/**
 * A paid order becoming a real server, through the real simulated provider.
 *
 * No mock stands in for the provider here. FakeProvider keeps its state in
 * PostgreSQL, enforces a unique index on the provisioning token and leaves a
 * tombstone when a server is deleted — which is what makes "exactly one server"
 * a claim about behaviour rather than about a stub returning what it was told.
 */
beforeEach(function (): void {
    app(RoleProvisioner::class)->sync();

    $this->floor = ProvisioningFloor::open();
    $this->provisioning = app(ProvisioningService::class);
});

it('turns a paid order into exactly one server', function (): void {
    $order = $this->floor->paidOrder();

    $result = $this->provisioning->provision($order);

    expect($result->state)->toBe(ProvisioningResult::Provisioned)
        ->and($result->outcome)->toBe(ProvisioningOutcome::Succeeded);

    $fresh = $order->fresh();

    expect($fresh->status)->toBe(OrderStatus::Provisioned)
        ->and($fresh->provisioned_at)->not->toBeNull()
        ->and($fresh->provisioning_uuid)->not->toBeNull()
        // One of everything, at the provider and locally.
        ->and(FakeProviderServer::query()->count())->toBe(1)
        ->and(Server::query()->count())->toBe(1)
        ->and(Subscription::query()->count())->toBe(1);
});

it('gives the customer a server that matches what they bought', function (): void {
    $order = $this->floor->paidOrder();

    $this->provisioning->provision($order);

    $server = Server::query()->firstOrFail();
    $remote = FakeProviderServer::query()->firstOrFail();

    expect($server->user_id)->toBe($this->floor->customer->id)
        ->and($server->order_id)->toBe($order->id)
        ->and($server->provider_id)->toBe($this->floor->provider->id)
        ->and($server->provider_server_id)->toBe($remote->provider_server_id)
        // The machine carries the order's token, which is what makes it
        // findable again if everything else is lost.
        ->and($server->provisioning_uuid)->toBe($order->fresh()->provisioning_uuid)
        ->and($server->status)->toBe(ServerStatus::Active)
        ->and($server->power_state)->toBe(ServerPowerState::On)
        ->and($server->ip_address)->not->toBeNull();
});

it('reproduces the order money exactly, to the last decimal place', function (): void {
    $order = $this->floor->paidOrder();

    $this->provisioning->provision($order);

    $server = Server::query()->firstOrFail();
    $cost = $order->cost_snapshot;

    // Not recomputed from today's rate, and not rounded. The customer was
    // quoted these numbers; the server records the same ones.
    expect($server->provider_cost)->toBe($cost['provider_cost'])
        ->and($server->exchange_rate)->toBe($cost['exchange_rate'])
        ->and($server->local_cost_toman)->toBe($cost['converted_provider_cost_toman'])
        ->and($server->gross_margin_toman)->toBe($cost['gross_margin_toman'])
        ->and($server->selling_price_toman)->toBe($order->total_toman)
        ->and($server->selling_price_toman)->toBeInt();

    // Fractional Toman survive: these are derived money values, and rounding
    // them because the column says "toman" would lose real precision.
    expect($server->local_cost_toman)->toContain('.')
        ->and(is_float($server->local_cost_toman))->toBeFalse();
});

it('starts the service period at exactly 2,592,000 seconds', function (): void {
    $order = $this->floor->paidOrder();

    $this->provisioning->provision($order);

    $subscription = Subscription::query()->firstOrFail();
    $fresh = $order->fresh();

    expect($subscription->periodSeconds())->toBe(2_592_000)
        ->and($subscription->status)->toBe(SubscriptionStatus::Active)
        ->and($subscription->price_toman)->toBe($order->total_toman)
        ->and($subscription->cancel_at_period_end)->toBeFalse()
        // Phase 11 gives these meaning; inventing one here would be inventing
        // a policy.
        ->and($subscription->grace_until)->toBeNull()
        ->and($subscription->last_billed_at)->toBeNull()
        ->and($subscription->next_billing_at)->toBeNull()
        // The same instant, so the order and the subscription cannot disagree
        // about when service began.
        ->and($subscription->current_period_start->toIso8601String())
        ->toBe($fresh->provisioned_at->toIso8601String());
});

it('issues no second invoice for a purchase already invoiced', function (): void {
    $order = $this->floor->paidOrder();

    // Phase 6 raised the purchase invoice when the customer paid.
    expect(Invoice::query()->where('order_id', $order->id)->count())->toBe(1);

    $this->provisioning->provision($order);

    // Delivery adds a server, a subscription, an audit trail and a
    // notification. It does not bill the same purchase again.
    expect(Invoice::query()->where('order_id', $order->id)->count())->toBe(1);
});

it('records one attempt, one audit trail and one customer notification', function (): void {
    $order = $this->floor->paidOrder();

    $this->provisioning->provision($order);

    expect(ProvisioningAttempt::query()->count())->toBe(1)
        ->and(ProvisioningAttempt::query()->firstOrFail()->outcome)->toBe(ProvisioningOutcome::Succeeded)
        ->and(AuditLog::query()->where('event', AuditEvent::OrderProvisioningStarted)->count())->toBe(1)
        ->and(AuditLog::query()->where('event', AuditEvent::OrderProvisioned)->count())->toBe(1)
        ->and(AuditLog::query()->where('event', AuditEvent::ServerCreated)->count())->toBe(1)
        ->and(AuditLog::query()->where('event', AuditEvent::SubscriptionCreated)->count())->toBe(1)
        ->and(OutboxMessage::query()->where('topic', OutboxTopic::ProvisioningSucceeded)->count())->toBe(1);
});

it('tells the customer their server without telling them a password', function (): void {
    $order = $this->floor->paidOrder();

    $this->provisioning->provision($order);

    $message = OutboxMessage::query()->where('topic', OutboxTopic::ProvisioningSucceeded)->firstOrFail();
    $payload = $message->payload;
    $server = Server::query()->firstOrFail();

    expect($payload['order_number'])->toBe($order->order_number)
        ->and($payload['server_id'])->toBe($server->getKey())
        ->and($payload['ip_address'])->toBe($server->ip_address)
        ->and($payload['current_period_end'])->not->toBeNull()
        // A credential is revealed through a deliberate, audited flow. Never in
        // a notification payload, which is rendered into a chat message.
        ->and(array_keys($payload))->not->toContain('root_password')
        ->and(array_keys($payload))->not->toContain('root_password_encrypted');

    $encoded = json_encode($payload, JSON_THROW_ON_ERROR);

    expect(strtolower($encoded))->not->toContain('password');
});

it('does nothing a second time, however many times the job runs', function (): void {
    $order = $this->floor->paidOrder();

    $first = $this->provisioning->provision($order);
    $subscription = Subscription::query()->firstOrFail();
    $start = $subscription->current_period_start->toIso8601String();
    $end = $subscription->current_period_end->toIso8601String();

    // Three more runs, as a duplicated queue message or a re-dispatch would.
    $this->provisioning->provision($order->fresh());
    $this->provisioning->provision($order->fresh());
    $this->provisioning->provision($order->fresh());

    $after = Subscription::query()->firstOrFail();

    expect(FakeProviderServer::query()->count())->toBe(1)
        ->and(Server::query()->count())->toBe(1)
        ->and(Subscription::query()->count())->toBe(1)
        ->and(Invoice::query()->where('order_id', $order->id)->count())->toBe(1)
        // The customer's 30 days do not restart because a worker ran again.
        ->and($after->current_period_start->toIso8601String())->toBe($start)
        ->and($after->current_period_end->toIso8601String())->toBe($end)
        // And they are not told four times that their server is ready.
        ->and(OutboxMessage::query()->where('topic', OutboxTopic::ProvisioningSucceeded)->count())->toBe(1)
        ->and(AuditLog::query()->where('event', AuditEvent::OrderProvisioned)->count())->toBe(1)
        ->and($first->state)->toBe(ProvisioningResult::Provisioned);
});

it('refuses to provision an order nobody has paid for', function (): void {
    $orders = app(App\Orders\OrderService::class);
    $order = $orders->place(new App\Orders\Data\PurchaseIntent(
        $this->floor->customer, $this->floor->price, ProvisioningFloor::AUP_VERSION, true,
        (string) Illuminate\Support\Str::uuid(),
    ));

    $result = $this->provisioning->provision($order);

    expect($result->state)->toBe(ProvisioningResult::NotEligible)
        ->and($order->fresh()->status)->toBe(OrderStatus::Pending)
        ->and($order->fresh()->provisioning_uuid)->toBeNull()
        ->and(FakeProviderServer::query()->count())->toBe(0);
});
