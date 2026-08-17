<?php

use App\Exceptions\ProviderException;
use App\Jobs\ProvisionServerJob;
use App\Models\AuditLog;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Server;
use App\Models\Subscription;
use App\Models\User;
use App\Services\AuditService;
use App\Services\OrderService;
use App\Services\PaymentService;
use App\Services\ProviderManager;
use Database\Seeders\FakeProviderSeeder;

beforeEach(function () {
    $this->seed(FakeProviderSeeder::class);
    $this->user = User::factory()->create();
});

it('provisions a server end-to-end after a manual payment', function () {
    $product = Product::query()->where('slug', 'vps-cx21')->firstOrFail();

    // 1. Create the order with a cost/FX snapshot.
    $order = app(OrderService::class)->place($this->user, $product);

    expect($order->status)->toBe(Order::STATUS_PENDING);
    expect($order->items)->toHaveCount(1);
    expect($order->total_toman)->toBeGreaterThan(0);
    expect($order->cost_snapshot)->not->toBeNull();
    expect($order->cost_snapshot['selling_price'])->toBeInt();
    expect((float) $order->cost_snapshot['exchange_rate'])->toBe(450000.0);

    // 2. Invoice + payment via ManualGateway.
    $invoice = app(OrderService::class)->createInvoice($order, 'manual');
    $payment = app(PaymentService::class)->start($invoice, 'manual');
    $payment = app(PaymentService::class)->confirm($payment, ['approved' => true], $this->user);

    expect($payment->status)->toBe(Payment::STATUS_PAID);
    expect($payment->verified_at)->not->toBeNull();
    expect($invoice->fresh()->status)->toBe(Invoice::STATUS_PAID);
    expect($order->fresh()->status)->toBe(Order::STATUS_PAID);

    // 3. Queued provisioning (sync queue in tests) via FakeProvider.
    app(PaymentService::class)->provision($order->fresh());

    $order->refresh();

    expect($order->status)->toBe(Order::STATUS_PROVISIONED);
    expect($order->provisioned_at)->not->toBeNull();

    // 4. Server record created with provider data + cost snapshot.
    $server = $order->server;

    expect($server)->not->toBeNull();
    expect($server->provider_server_id)->toStartWith('fake-');
    expect($server->ip_address)->toStartWith('10.0.0.');
    expect($server->status)->toBe(Server::STATUS_RUNNING);
    expect($server->power_state)->toBe('running');
    expect($server->root_password_encrypted)->not->toBeEmpty();
    expect($server->selling_price)->toBe($order->total_toman);
    expect($server->gross_margin)->toBe($order->cost_snapshot['gross_margin']);
    expect($server->expires_at)->not->toBeNull();
    expect($server->expires_at->greaterThan(now()))->toBeTrue();

    expect(Subscription::query()->where('server_id', $server->id)->exists())->toBeTrue();
    expect(AuditLog::query()->where('action', 'server.provisioned')->exists())->toBeTrue();
});

it('keeps the order paid and unprovisioned when the provider fails', function () {
    $product = Product::query()->where('slug', 'vps-cx21')->firstOrFail();

    // Simulate a provider-side failure (e.g. insufficient balance).
    $product->provider->update(['settings' => ['fail_create' => true]]);

    $order = app(OrderService::class)->place($this->user, $product);
    $invoice = app(OrderService::class)->createInvoice($order, 'manual');
    $payment = app(PaymentService::class)->start($invoice, 'manual');
    app(PaymentService::class)->confirm($payment, ['approved' => true], $this->user);

    $job = new ProvisionServerJob($order->fresh());

    try {
        $job->handle(app(ProviderManager::class), app(AuditService::class));
        $this->fail('Expected a ProviderException to be thrown.');
    } catch (ProviderException) {
        // Expected.
    }

    expect($order->fresh()->status)->toBe(Order::STATUS_PAID);
    expect(Server::query()->where('order_id', $order->id)->exists())->toBeFalse();
    expect(AuditLog::query()->where('action', 'provision.failed')->exists())->toBeTrue();
});

it('is idempotent when confirming the same payment twice', function () {
    $product = Product::query()->where('slug', 'vps-cx21')->firstOrFail();
    $order = app(OrderService::class)->place($this->user, $product);
    $invoice = app(OrderService::class)->createInvoice($order, 'manual');
    $payment = app(PaymentService::class)->start($invoice, 'manual');

    app(PaymentService::class)->confirm($payment, ['approved' => true], $this->user);
    $second = app(PaymentService::class)->confirm($payment->fresh(), ['approved' => true], $this->user);

    expect($second->status)->toBe(Payment::STATUS_PAID);
    expect($order->fresh()->status)->toBe(Order::STATUS_PAID);
    expect($invoice->fresh()->status)->toBe(Invoice::STATUS_PAID);
    expect(Payment::query()->where('id', $payment->id)->count())->toBe(1);
});

it('protects the server record with the owning user', function () {
    $product = Product::query()->where('slug', 'vps-cx21')->firstOrFail();
    $order = app(OrderService::class)->place($this->user, $product);
    $invoice = app(OrderService::class)->createInvoice($order, 'manual');
    $payment = app(PaymentService::class)->confirm(app(PaymentService::class)->start($invoice), ['approved' => true]);

    app(PaymentService::class)->provision($order->fresh());

    expect($order->fresh()->server->user_id)->toBe($this->user->id);
    expect($this->user->servers()->count())->toBe(1);
});
