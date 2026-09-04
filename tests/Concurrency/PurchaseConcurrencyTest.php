<?php

declare(strict_types=1);

use App\Authorization\RoleProvisioner;
use App\Enums\ServerActionType;
use App\Enums\SettingKey;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\OutboxMessage;
use App\Models\Server;
use App\Models\ServerAction;
use App\Models\Subscription;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Orders\Data\PurchaseIntent;
use App\Orders\OrderService;
use App\Outbox\OutboxTopic;
use App\Servers\ServerActionService;
use App\Settings\SettingsService;
use App\Telegram\Flows\BuyServerFlow;
use Illuminate\Support\Facades\DB;
use Tests\Support\Concurrency\ForkedWorkers;
use Tests\Support\Provisioning\ProvisioningFloor;

/**
 * Buying and managing, in genuinely separate processes.
 *
 * Nothing here simulates contention. Each worker is a real process with its own
 * database connection, released together by a wall-clock barrier, so the races
 * are the real ones: two confirmations landing at once, six purchases going for
 * the last permitted slot, two workers reaching for the same delete.
 *
 * Redis coordinates. PostgreSQL decides — every guarantee proven here holds
 * whether or not a lock was available.
 */
function resetPurchaseTables(): void
{
    DB::statement('TRUNCATE server_actions, notification_logs, subscriptions, servers, provisioning_attempts,
        outbox_messages, invoices, payments, wallet_transactions, orders,
        fake_provider_actions, fake_provider_servers,
        product_location_prices, products, provider_images, provider_locations, provider_plans,
        provider_credentials, providers, exchange_rates, settings, audit_logs,
        telegram_updates, telegram_accounts RESTART IDENTITY CASCADE');
    DB::table('model_has_roles')->delete();
    DB::table('users')->delete();
}

beforeEach(function (): void {
    resetPurchaseTables();

    app(RoleProvisioner::class)->sync();

    $this->floor = ProvisioningFloor::open();
});

afterEach(function (): void {
    resetPurchaseTables();
});

it('creates one order when the same purchase is confirmed at once', function (): void {
    // One purchase intent, six processes. The key is derived from the intent
    // generated when the flow began, so every worker carries the same one.
    $intentId = (string) Illuminate\Support\Str::uuid();
    $customerId = (int) $this->floor->customer->getKey();
    $priceId = (int) $this->floor->price->getKey();
    $charged = (int) $this->floor->customer->fresh()->wallet_balance_toman;

    $results = ForkedWorkers::run(6, function () use ($intentId, $customerId, $priceId): array {
        $orders = app(OrderService::class);
        $customer = User::query()->findOrFail($customerId);

        try {
            $order = $orders->place(new PurchaseIntent(
                $customer,
                App\Models\ProductLocationPrice::query()->findOrFail($priceId),
                ProvisioningFloor::AUP_VERSION,
                true,
                BuyServerFlow::orderKey($intentId),
            ));

            $paid = $orders->payFromWallet($orders->awaitPayment($order), $customer);

            return ['order' => $paid->getKey()];
        } catch (Throwable $exception) {
            return ['error' => $exception::class];
        }
    });

    $orders = array_values(array_unique(array_filter(array_column($results, 'order'))));

    expect(Order::query()->count())->toBe(1)
        // Every worker that got an answer got the same order.
        ->and($orders)->toHaveCount(1)
        // One debit, one invoice, one promise to build it.
        ->and(WalletTransaction::query()->where('type', 'debit')->count())->toBe(1)
        ->and(Invoice::query()->count())->toBe(1)
        ->and(OutboxMessage::query()->where('topic', OutboxTopic::ProvisioningRequested)->count())->toBe(1)
        ->and(User::query()->findOrFail($customerId)->wallet_balance_toman)
        ->toBe($charged - Order::query()->sole()->total_toman);

    // And the ledger still explains the balance exactly.
    expect(User::query()->findOrFail($customerId)->wallet_balance_toman)
        ->toBe((int) WalletTransaction::query()->where('user_id', $customerId)->sum('amount_toman'));
});

it('cannot be raced past the active-server ceiling', function (): void {
    app(SettingsService::class)->set(SettingKey::AntiAbuseMaxActiveServers, 2, $this->floor->owner);

    $customerId = (int) $this->floor->customer->getKey();
    $priceId = (int) $this->floor->price->getKey();

    // Six distinct purchases, all at once. Checked without the customer's row
    // locked, every one of them would see room.
    $results = ForkedWorkers::run(6, function (int $index) use ($customerId, $priceId): array {
        try {
            app(OrderService::class)->place(new PurchaseIntent(
                User::query()->findOrFail($customerId),
                App\Models\ProductLocationPrice::query()->findOrFail($priceId),
                ProvisioningFloor::AUP_VERSION,
                true,
                'race-'.$index,
            ));

            return ['placed' => true];
        } catch (Throwable $exception) {
            return ['placed' => false, 'refusal' => $exception::class];
        }
    });

    expect(Order::query()->count())->toBe(2)
        ->and(array_filter(array_column($results, 'placed')))->toHaveCount(2);
});

it('cannot be raced past the purchase velocity limit', function (): void {
    app(SettingsService::class)->set(SettingKey::AntiAbusePurchaseLimitCount, 3, $this->floor->owner);
    app(SettingsService::class)->set(SettingKey::AntiAbusePurchaseWindowMinutes, 60, $this->floor->owner);
    // Well clear of the ceiling, so the velocity limit is what is being tested.
    app(SettingsService::class)->set(SettingKey::AntiAbuseMaxActiveServers, 50, $this->floor->owner);

    $customerId = (int) $this->floor->customer->getKey();
    $priceId = (int) $this->floor->price->getKey();

    ForkedWorkers::run(6, function (int $index) use ($customerId, $priceId): array {
        try {
            app(OrderService::class)->place(new PurchaseIntent(
                User::query()->findOrFail($customerId),
                App\Models\ProductLocationPrice::query()->findOrFail($priceId),
                ProvisioningFloor::AUP_VERSION,
                true,
                'velocity-'.$index,
            ));

            return ['placed' => true];
        } catch (Throwable) {
            return ['placed' => false];
        }
    });

    expect(Order::query()->count())->toBe(3);
});

it('keeps two customers\' limits separate under contention', function (): void {
    app(SettingsService::class)->set(SettingKey::AntiAbuseMaxActiveServers, 1, $this->floor->owner);

    $first = (int) $this->floor->customer->getKey();
    $second = (int) User::factory()->fromTelegram()->create()->getKey();
    app(App\Wallet\WalletService::class)->credit(
        User::query()->findOrFail($second), 5_000_000, 'second-'.bin2hex(random_bytes(4)), 'Top-up',
    );

    $priceId = (int) $this->floor->price->getKey();

    ForkedWorkers::run(6, function (int $index) use ($first, $second, $priceId): array {
        $customerId = $index % 2 === 0 ? $first : $second;

        try {
            app(OrderService::class)->place(new PurchaseIntent(
                User::query()->findOrFail($customerId),
                App\Models\ProductLocationPrice::query()->findOrFail($priceId),
                ProvisioningFloor::AUP_VERSION,
                true,
                'pair-'.$index,
            ));

            return ['customer' => $customerId];
        } catch (Throwable) {
            return [];
        }
    });

    // One each. A lock on the wrong row would have serialized everybody, and a
    // lock on no row would have let everybody through.
    expect(Order::query()->where('user_id', $first)->count())->toBe(1)
        ->and(Order::query()->where('user_id', $second)->count())->toBe(1);
});

it('records one action when six workers request the same one', function (): void {
    app(App\Provisioning\ProvisioningService::class)->provision($this->floor->paidOrder());

    $serverId = (int) Server::query()->sole()->getKey();
    $customerId = (int) $this->floor->customer->getKey();

    $results = ForkedWorkers::run(6, function () use ($customerId, $serverId): array {
        try {
            $action = app(ServerActionService::class)->request(
                User::query()->findOrFail($customerId),
                $serverId,
                ServerActionType::Reboot,
                'telegram:update:777:server:'.$serverId.':reboot',
            );

            return ['action' => $action->getKey()];
        } catch (Throwable $exception) {
            return ['error' => $exception::class];
        }
    });

    $actions = array_values(array_unique(array_filter(array_column($results, 'action'))));

    expect(ServerAction::query()->count())->toBe(1)
        ->and($actions)->toHaveCount(1)
        ->and(array_filter(array_column($results, 'error')))->toBe([])
        // One intent to perform it, too.
        ->and(OutboxMessage::query()->where('topic', OutboxTopic::ServerActionRequested)->count())->toBe(1);
});

it('performs one remote operation when six workers execute one action', function (): void {
    app(App\Provisioning\ProvisioningService::class)->provision($this->floor->paidOrder());

    $serverId = (int) Server::query()->sole()->getKey();

    $action = app(ServerActionService::class)->request(
        $this->floor->customer,
        $serverId,
        ServerActionType::Reboot,
        'concurrent-reboot',
    );
    $actionId = (int) $action->getKey();

    ForkedWorkers::run(6, function () use ($actionId): array {
        app()->call([new App\Jobs\ExecuteServerActionJob($actionId), 'handle']);

        return [];
    });

    // Serialized by the server's lock and settled by a compare-and-set: two
    // workers cannot both reboot a customer's machine.
    expect(App\Cloud\Fake\Models\FakeProviderAction::query()->where('command', 'reboot')->count())->toBe(1)
        ->and(ServerAction::query()->sole()->status->value)->toBe('succeeded');
});

it('terminates a server once when workers race to finish its deletion', function (): void {
    app(App\Provisioning\ProvisioningService::class)->provision($this->floor->paidOrder());

    $serverId = (int) Server::query()->sole()->getKey();

    $action = app(ServerActionService::class)->request(
        $this->floor->customer,
        $serverId,
        ServerActionType::Delete,
        'concurrent-delete',
    );
    $actionId = (int) $action->getKey();

    ForkedWorkers::run(6, function () use ($actionId): array {
        app()->call([new App\Jobs\ExecuteServerActionJob($actionId), 'handle']);

        return [];
    });

    expect(App\Cloud\Fake\Models\FakeProviderAction::query()->where('command', 'delete')->count())->toBe(1)
        ->and(Server::query()->sole()->status->value)->toBe('terminated')
        ->and(Subscription::query()->sole()->status->value)->toBe('cancelled')
        // One farewell, one action, and no refund.
        ->and(OutboxMessage::query()->where('topic', OutboxTopic::ServerTerminated)->count())->toBe(1)
        ->and(ServerAction::query()->count())->toBe(1)
        ->and(WalletTransaction::query()->where('type', 'refund')->count())->toBe(0);
});

it('delivers one notification when workers race on one outbox row', function (): void {
    $order = $this->floor->paidOrder();

    $message = OutboxMessage::query()
        ->where('topic', OutboxTopic::ProvisioningRequested)
        ->sole();
    $messageId = (int) $message->getKey();

    ForkedWorkers::run(6, function () use ($messageId): array {
        app()->call([new App\Jobs\ProcessOutboxMessageJob($messageId), 'handle']);

        return [];
    });

    unset($order);

    // Marked processed by exactly one worker.
    expect(OutboxMessage::query()->whereKey($messageId)->sole()->processed_at)->not->toBeNull()
        ->and(OutboxMessage::query()->whereKey($messageId)->sole()->attempts)->toBeGreaterThanOrEqual(1);
});
