<?php

use App\Exceptions\ProviderAuthenticationException;
use App\Jobs\ProvisionServerJob;
use App\Models\AuditLog;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Provider;
use App\Models\ProviderCredential;
use App\Models\ProviderImage;
use App\Models\ProviderLocation;
use App\Models\ProviderPlan;
use App\Models\Server;
use App\Models\Subscription;
use App\Models\User;
use App\Providers\Cloud\HetznerProvider;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Fixtures\HetznerApiFixtures as F;

function hetznerProvisioningChain(): array
{
    $provider = Provider::create([
        'code' => 'hetzner',
        'name' => 'Hetzner Cloud',
        'class' => HetznerProvider::class,
        'enabled' => true,
        'capabilities' => (new HetznerProvider)->capabilities(),
        'settings' => ['base_url' => F::BASE_URL, 'retry_attempts' => 1, 'retry_delay_ms' => 1],
    ]);

    ProviderCredential::create([
        'provider_id' => $provider->id,
        'name' => 'Production',
        'credentials' => ['token' => F::TOKEN],
        'is_active' => true,
    ]);

    $plan = ProviderPlan::create([
        'provider_id' => $provider->id,
        'provider_plan_id' => 'cx22',
        'name' => 'CX22',
        'vcpu' => 2,
        'ram_mb' => 4096,
        'disk_gb' => 40,
        'price_monthly' => 4.29,
        'currency' => 'EUR',
        'enabled' => true,
    ]);

    $location = ProviderLocation::create([
        'provider_id' => $provider->id,
        'provider_location_id' => 'fsn1',
        'name' => 'Falkenstein DC Park 1',
        'country_code' => 'DE',
        'city' => 'Falkenstein',
        'enabled' => true,
    ]);

    $image = ProviderImage::create([
        'provider_id' => $provider->id,
        'provider_image_id' => '1001',
        'name' => 'Ubuntu 24.04',
        'os_family' => 'linux',
        'os_distro' => 'ubuntu',
        'version' => '24.04',
        'architecture' => 'x86',
        'enabled' => true,
        'deprecated' => null,
    ]);

    $product = Product::create([
        'provider_id' => $provider->id,
        'provider_plan_id' => $plan->id,
        'name' => 'Starter VPS',
        'slug' => 'starter-vps',
        'status' => Product::STATUS_ACTIVE,
        'billing_cycle' => Product::BILLING_MONTHLY,
        'markup_strategy' => Product::MARKUP_PERCENTAGE,
        'markup_value' => 20,
        'price_toman' => 399000,
        'enabled' => true,
    ]);

    $user = User::factory()->create();

    $order = Order::create([
        'order_number' => 'ORD-2026-0001',
        'user_id' => $user->id,
        'status' => Order::STATUS_PAID,
        'total_toman' => 399000,
        'gateway_code' => 'manual',
        'paid_at' => now(),
        'cost_snapshot' => [
            'provider' => 'hetzner',
            'provider_cost' => 4.29,
            'provider_currency' => 'EUR',
            'exchange_rate' => 450000,
            'local_cost' => 1930500,
            'selling_price' => 3990000,
            'gross_margin' => 2059500,
        ],
    ]);

    OrderItem::create([
        'order_id' => $order->id,
        'product_id' => $product->id,
        'provider_plan_id' => $plan->id,
        'name' => 'Starter VPS',
        'quantity' => 1,
        'unit_price_toman' => 399000,
        'line_total_toman' => 399000,
    ]);

    return compact('provider', 'plan', 'location', 'image', 'product', 'user', 'order');
}

it('provisions a real Hetzner server end-to-end from a paid order', function () {
    Http::fake([
        'api.hetzner.test/v1/servers*' => function (Request $request) {
            // POST /servers creates; the adapter then confirms via GET /servers/{id}.
            if ($request->method() === 'POST') {
                return Http::response(F::createdServerResponse());
            }

            return Http::response(F::serverResponse(status: 'initializing'));
        },
    ]);

    ['order' => $order, 'location' => $location] = hetznerProvisioningChain();

    ProvisionServerJob::dispatchSync($order);

    $order->refresh();
    expect($order->status)->toBe(Order::STATUS_PROVISIONED);
    expect($order->provisioned_at)->not->toBeNull();

    $server = Server::where('order_id', $order->id)->first();
    expect($server)->not->toBeNull();
    expect($server->provider_server_id)->toBe('1234');
    expect($server->ip_address)->toBe('1.2.3.4');
    expect($server->status)->toBe(Server::STATUS_PROVISIONING); // provider said initializing
    expect($server->provider_location_id)->toBe($location->id);
    expect($server->provider_metadata['provisioning_uuid'])->not->toBeEmpty();

    // Cost/FX snapshot preserved from the order — never recalculated.
    expect((float) $server->provider_cost)->toBe(4.29);
    expect($server->provider_currency)->toBe('EUR');
    expect($server->selling_price)->toBe(3990000);

    // Root password delivered once and stored encrypted at rest (the model's
    // encrypted cast round-trips the value; the column itself holds ciphertext).
    expect($server->root_password_encrypted)->toBe('h3tz-ROOT-p4ssw0rd!');
    expect($server->getRawOriginal('root_password_encrypted'))->not->toBe('h3tz-ROOT-p4ssw0rd!');

    $subscription = Subscription::where('server_id', $server->id)->first();
    expect($subscription->status)->toBe(Subscription::STATUS_ACTIVE);
    expect($subscription->price_toman)->toBe(399000);

    expect(AuditLog::where('action', 'server.provisioned')->count())->toBe(1);

    // The create call carried the idempotency label.
    Http::assertSent(function (Request $request): bool {
        if ($request->method() !== 'POST' || ! str_ends_with($request->url(), '/servers')) {
            return false;
        }

        return ($request->data()['server_type'] ?? null) === 'cx22'
            && ($request->data()['image'] ?? null) === 1001
            && ($request->data()['location'] ?? null) === 'fsn1'
            && isset($request->data()['labels']['provisioning-uuid']);
    });
});

it('never marks an order provisioned when the provider call fails', function () {
    Http::fake(['api.hetzner.test/v1/servers' => Http::response(F::error(401, 'unauthorized', 'unable to authorize you'), 401)]);

    ['order' => $order] = hetznerProvisioningChain();

    expect(fn () => ProvisionServerJob::dispatchSync($order))
        ->toThrow(ProviderAuthenticationException::class);

    $order->refresh();
    expect($order->status)->toBe(Order::STATUS_PAID);
    expect($order->provisioned_at)->toBeNull();

    expect(Server::where('order_id', $order->id)->count())->toBe(0);
    expect(Subscription::where('user_id', $order->user_id)->count())->toBe(0);

    expect(AuditLog::where('action', 'provision.failed')->count())->toBe(1);
});

it('is idempotent — a duplicate provisioning dispatch never creates a second server', function () {
    Http::fake([
        'api.hetzner.test/v1/servers*' => function (Request $request) {
            if ($request->method() === 'POST') {
                return Http::response(F::createdServerResponse());
            }

            return Http::response(F::serverResponse(status: 'initializing'));
        },
    ]);

    ['order' => $order] = hetznerProvisioningChain();

    ProvisionServerJob::dispatchSync($order);
    ProvisionServerJob::dispatchSync($order->fresh());

    expect(Server::where('order_id', $order->id)->count())->toBe(1);

    // Exactly one POST /servers was ever sent.
    $createCalls = collect(Http::recorded())->filter(
        fn (array $pair): bool => $pair[0]->method() === 'POST' && str_ends_with($pair[0]->url(), '/servers')
    );
    expect($createCalls)->toHaveCount(1);
});

it('skips provisioning entirely for unpaid orders', function () {
    Http::fake(['api.hetzner.test/v1/servers' => Http::response(F::createdServerResponse())]);

    $chain = hetznerProvisioningChain();
    $chain['order']->update(['status' => Order::STATUS_PENDING]);

    ProvisionServerJob::dispatchSync($chain['order']);

    expect(Server::where('order_id', $chain['order']->id)->count())->toBe(0);
    Http::assertNothingSent();
});
