<?php

declare(strict_types=1);

use App\Authorization\RoleProvisioner;
use App\Enums\OrderStatus;
use App\Enums\PurchaseRefusalReason;
use App\Enums\ServerStatus;
use App\Enums\SettingKey;
use App\Models\Order;
use App\Models\Server;
use App\Orders\Data\PurchaseIntent;
use App\Orders\Exceptions\PurchaseNotAllowed;
use App\Orders\OrderService;
use App\Orders\PurchasePolicyService;
use App\Settings\SettingsService;
use Illuminate\Support\Str;
use Tests\Support\Provisioning\ProvisioningFloor;

/**
 * The abuse ceilings, enforced where orders are created.
 *
 * Not in the Telegram flow. A limit that lives only where the buttons are is a
 * limit that disappears the moment a purchase arrives by any other route, so
 * every test here goes through OrderService — the boundary that a future admin
 * tool, a future API and the bot all share.
 *
 * The fail-closed cases matter most. A shop selling with no ceiling because
 * nobody configured one behaves exactly like a shop with no ceiling, and that
 * is how a stolen card funds a botnet overnight.
 */
beforeEach(function (): void {
    app(RoleProvisioner::class)->sync();

    $this->floor = ProvisioningFloor::open();
    $this->orders = app(OrderService::class);
    $this->settings = app(SettingsService::class);
});

function placeOrderFor(ProvisioningFloor $floor): Order
{
    return app(OrderService::class)->place(new PurchaseIntent(
        $floor->customer,
        $floor->price,
        ProvisioningFloor::AUP_VERSION,
        true,
        (string) Str::uuid(),
    ));
}

function abuseRefusalFor(ProvisioningFloor $floor): PurchaseRefusalReason
{
    try {
        placeOrderFor($floor);
    } catch (PurchaseNotAllowed $blocked) {
        return $blocked->reason;
    }

    throw new RuntimeException('The purchase was not refused.');
}

it('sells while the customer is below both limits', function (): void {
    expect(placeOrderFor($this->floor)->status)->toBe(OrderStatus::Pending);
});

it('refuses when the server ceiling is missing', function (): void {
    $this->settings->set(SettingKey::AntiAbuseMaxActiveServers, null, $this->floor->owner);

    expect(abuseRefusalFor($this->floor))->toBe(PurchaseRefusalReason::LimitsNotConfigured)
        ->and(Order::query()->count())->toBe(0);
});

it('refuses when a ceiling is unreadable', function (string $value): void {
    // Written straight to the row, past the typed setter: the question is what
    // happens when a row already holds nonsense, which is how a hand-edited
    // database or a bad migration actually presents.
    App\Models\Setting::query()->where('key', SettingKey::AntiAbuseMaxActiveServers->value)
        ->update(['value' => $value]);

    expect(abuseRefusalFor($this->floor))->toBe(PurchaseRefusalReason::LimitsNotConfigured)
        ->and(Order::query()->count())->toBe(0);
})->with([
    'words' => ['three'],
    'a number with units' => ['3 servers'],
    'empty' => [''],
    'zero' => ['0'],
    'negative' => ['-1'],
]);

it('refuses when the velocity limit is missing', function (): void {
    $this->settings->set(SettingKey::AntiAbusePurchaseLimitCount, null, $this->floor->owner);

    expect(abuseRefusalFor($this->floor))->toBe(PurchaseRefusalReason::LimitsNotConfigured);
});

it('refuses when the velocity window is missing', function (): void {
    $this->settings->set(SettingKey::AntiAbusePurchaseWindowMinutes, null, $this->floor->owner);

    expect(abuseRefusalFor($this->floor))->toBe(PurchaseRefusalReason::LimitsNotConfigured);
});

it('stops the customer at the configured server ceiling', function (): void {
    $this->settings->set(SettingKey::AntiAbuseMaxActiveServers, 2, $this->floor->owner);

    placeOrderFor($this->floor);
    placeOrderFor($this->floor);

    // Two funded-but-undelivered orders already count. Waiting for the servers
    // to exist would let somebody place ten before the first one finished.
    expect(abuseRefusalFor($this->floor))->toBe(PurchaseRefusalReason::ActiveServerLimitReached)
        ->and(Order::query()->count())->toBe(2);
});

it('counts a live server against the ceiling', function (): void {
    $this->settings->set(SettingKey::AntiAbuseMaxActiveServers, 1, $this->floor->owner);

    $order = $this->floor->paidOrder();

    app(App\Provisioning\ProvisioningService::class)->provision($order);

    expect(Server::query()->count())->toBe(1)
        ->and(abuseRefusalFor($this->floor))->toBe(PurchaseRefusalReason::ActiveServerLimitReached);
});

it('frees the slot when a server is terminated', function (): void {
    $this->settings->set(SettingKey::AntiAbuseMaxActiveServers, 1, $this->floor->owner);

    $order = $this->floor->paidOrder();
    app(App\Provisioning\ProvisioningService::class)->provision($order);

    Server::query()->sole()->forceFill([
        'status' => ServerStatus::Terminated->value,
        'terminated_at' => now(),
    ])->save();

    // A deleted server is not a commitment. Suspended and needs-attention ones
    // still are, which is why only termination frees a slot.
    expect(placeOrderFor($this->floor)->status)->toBe(OrderStatus::Pending);
});

it('counts a suspended server against the ceiling', function (): void {
    $this->settings->set(SettingKey::AntiAbuseMaxActiveServers, 1, $this->floor->owner);

    $order = $this->floor->paidOrder();
    app(App\Provisioning\ProvisioningService::class)->provision($order);

    Server::query()->sole()->forceFill(['status' => ServerStatus::Suspended->value])->save();

    expect(abuseRefusalFor($this->floor))->toBe(PurchaseRefusalReason::ActiveServerLimitReached);
});

it('does not double-count an order that already has its server', function (): void {
    $this->settings->set(SettingKey::AntiAbuseMaxActiveServers, 2, $this->floor->owner);

    $order = $this->floor->paidOrder();
    app(App\Provisioning\ProvisioningService::class)->provision($order);

    // One order, one server, one slot used — not two.
    expect(app(PurchasePolicyService::class)->capacityUsed($this->floor->customer))->toBe(1)
        ->and(placeOrderFor($this->floor)->status)->toBe(OrderStatus::Pending);
});

it('stops the customer at the configured velocity', function (): void {
    $this->settings->set(SettingKey::AntiAbusePurchaseLimitCount, 2, $this->floor->owner);
    $this->settings->set(SettingKey::AntiAbusePurchaseWindowMinutes, 60, $this->floor->owner);

    placeOrderFor($this->floor);
    placeOrderFor($this->floor);

    expect(abuseRefusalFor($this->floor))->toBe(PurchaseRefusalReason::PurchaseVelocityExceeded);
});

it('lets the customer buy again once the window has passed', function (): void {
    $this->settings->set(SettingKey::AntiAbusePurchaseLimitCount, 1, $this->floor->owner);
    $this->settings->set(SettingKey::AntiAbusePurchaseWindowMinutes, 30, $this->floor->owner);

    $first = placeOrderFor($this->floor);

    expect(abuseRefusalFor($this->floor))->toBe(PurchaseRefusalReason::PurchaseVelocityExceeded);

    // The order is real history; only its age changes.
    Order::query()->whereKey($first->getKey())->update(['created_at' => now()->subMinutes(31)]);

    expect(placeOrderFor($this->floor)->status)->toBe(OrderStatus::Pending);
});

it('counts orders rather than telegram taps', function (): void {
    $this->settings->set(SettingKey::AntiAbusePurchaseLimitCount, 5, $this->floor->owner);

    placeOrderFor($this->floor);
    placeOrderFor($this->floor);

    // Read from persisted order history: a client that stopped pressing
    // buttons could evade a counter that watched button presses.
    expect(app(PurchasePolicyService::class)->ordersCreatedWithin($this->floor->customer, 60))->toBe(2);
});

it('refuses a suspended customer before any limit is consulted', function (): void {
    $this->floor->customer->forceFill(['status' => 'suspended'])->save();

    expect(fn () => placeOrderFor($this->floor))
        ->toThrow(App\Orders\Exceptions\OrderNotPlaceable::class);

    expect(Order::query()->count())->toBe(0);
});

it('tells the customer where they stand', function (): void {
    $this->settings->set(SettingKey::AntiAbuseMaxActiveServers, 2, $this->floor->owner);

    placeOrderFor($this->floor);
    placeOrderFor($this->floor);

    try {
        placeOrderFor($this->floor);
        $this->fail('The purchase was not refused.');
    } catch (PurchaseNotAllowed $blocked) {
        // A limit is only fair if the person hitting it can see it.
        expect($blocked->limit)->toBe(2)
            ->and($blocked->observed)->toBe(2)
            ->and(App\Telegram\Flows\BuyMessages::purchaseBlocked($blocked))->toContain('2');
    }
});

it('says nothing about which control is missing', function (): void {
    $this->settings->set(SettingKey::AntiAbuseMaxActiveServers, null, $this->floor->owner);

    try {
        placeOrderFor($this->floor);
        $this->fail('The purchase was not refused.');
    } catch (PurchaseNotAllowed $blocked) {
        $message = App\Telegram\Flows\BuyMessages::purchaseBlocked($blocked);

        // An unconfigured ceiling is an operator's problem. Naming it would
        // tell whoever is probing exactly which control is absent.
        expect($message)->not->toContain(SettingKey::AntiAbuseMaxActiveServers->value)
            ->and($message)->not->toContain('anti_abuse');
    }
});

it('cannot be evaded by stockpiling unpaid orders and paying them later', function (): void {
    // The obvious hole in a funded-only count: place several orders while
    // holding nothing, each passing a check that sees no commitment, then pay
    // them all. The velocity limit slows that down and does not close it — a
    // patient customer waits out the window — so an unpaid order that could
    // still become a server holds a slot.
    $this->settings->set(SettingKey::AntiAbuseMaxActiveServers, 2, $this->floor->owner);

    placeOrderFor($this->floor);
    placeOrderFor($this->floor);

    expect(abuseRefusalFor($this->floor))->toBe(PurchaseRefusalReason::ActiveServerLimitReached)
        ->and(Order::query()->count())->toBe(2);
});

it('gives the slot back when an unpaid order is cancelled', function (): void {
    $this->settings->set(SettingKey::AntiAbuseMaxActiveServers, 1, $this->floor->owner);

    $order = placeOrderFor($this->floor);

    expect(abuseRefusalFor($this->floor))->toBe(PurchaseRefusalReason::ActiveServerLimitReached);

    // A customer sitting on a stale order is not stuck with it.
    $this->orders->cancel($order, $this->floor->customer);

    expect(placeOrderFor($this->floor)->status)->toBe(OrderStatus::Pending);
});

it('holds no slot for an order that can no longer become a server', function (string $status): void {
    $this->settings->set(SettingKey::AntiAbuseMaxActiveServers, 1, $this->floor->owner);

    $order = placeOrderFor($this->floor);
    Order::query()->whereKey($order->getKey())->update(['status' => $status]);

    // A purchase that went wrong must not count against the customer for ever.
    expect(app(PurchasePolicyService::class)->capacityUsed($this->floor->customer))->toBe(0)
        ->and(placeOrderFor($this->floor)->status)->toBe(OrderStatus::Pending);
})->with(['expired', 'cancelled', 'failed', 'refunded']);
