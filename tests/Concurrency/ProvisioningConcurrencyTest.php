<?php

declare(strict_types=1);

use App\Authorization\RoleProvisioner;
use App\Cloud\Data\CreateServerRequest;
use App\Cloud\Fake\FakeCatalog;
use App\Cloud\Fake\Models\FakeProviderServer;
use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\ProvisioningAttempt;
use App\Models\Server;
use App\Models\Subscription;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Provisioning\ProvisioningService;
use App\Provisioning\ReconciliationService;
use Illuminate\Support\Facades\DB;
use Tests\Support\Concurrency\ForkedWorkers;
use Tests\Support\Provisioning\CommitProbe;
use Tests\Support\Provisioning\ProvisioningFloor;
use Tests\Support\Provisioning\Simulator;

/**
 * Two workers, one paid order, at the same instant, in genuinely separate
 * processes.
 *
 * These are the races a sequential test cannot produce: both processes read the
 * order before either has written anything. That is the moment a customer ends
 * up with two servers and one invoice, and it is the only way to find out
 * whether the compare-and-set, the durable token and the unique constraints
 * actually hold together.
 *
 * These tests also commit for real, which is what makes the token-visibility
 * proof possible at all: a second connection cannot see an uncommitted write,
 * so a transaction-wrapped test could never demonstrate durability.
 */
function resetProvisioningTables(): void
{
    DB::statement(
        'TRUNCATE subscriptions, servers, provisioning_attempts, outbox_messages, wallet_transactions,
         invoices, payments, orders, product_location_prices, products, provider_images, provider_plans,
         provider_locations, provider_credentials, providers, exchange_rates, settings, audit_logs,
         fake_provider_servers, fake_provider_actions RESTART IDENTITY CASCADE'
    );
    DB::table('users')->delete();
}

beforeEach(function (): void {
    resetProvisioningTables();
    app(RoleProvisioner::class)->sync();

    $this->floor = ProvisioningFloor::open(walletBalance: 20_000_000);
});

afterEach(function (): void {
    resetProvisioningTables();
});

it('proves the token is committed before the provider is ever called', function (): void {
    // The objective proof the specification asks for. A read on the
    // application's own connection would see its own uncommitted writes and
    // demonstrate nothing; this reads through a separate PostgreSQL session,
    // which by definition sees only what has actually landed.
    $order = $this->floor->paidOrder();
    $observed = [];

    $scripted = Simulator::script();
    $scripted->beforeCreate(function (CreateServerRequest $request) use ($order, &$observed): void {
        $probe = CommitProbe::open();

        $observed = [
            // No transaction of ours may be open around a provider call.
            'transaction_level' => DB::transactionLevel(),
            'outside' => $probe->readOrder((int) $order->getKey()),
            'token_sent' => $request->provisioningToken,
        ];

        $probe->close();
    });

    app(ProvisioningService::class)->provision($order);

    expect($observed['transaction_level'])->toBe(0)
        // Another session already sees the claim and the token, so a worker
        // dying at this instant would leave the record behind.
        ->and($observed['outside'])->not->toBeNull()
        ->and($observed['outside']['status'])->toBe(OrderStatus::Provisioning->value)
        ->and($observed['outside']['provisioning_uuid'])->not->toBeNull()
        // And the token handed to the provider is exactly that committed value.
        ->and($observed['token_sent'])->toBe($observed['outside']['provisioning_uuid'])
        ->and($observed['token_sent'])->toBe($order->fresh()->provisioning_uuid);
});

it('builds one server when four workers provision one order at once', function (): void {
    $order = $this->floor->paidOrder();
    $orderId = (int) $order->getKey();

    $results = ForkedWorkers::run(4, function () use ($orderId): array {
        $order = Order::query()->findOrFail($orderId);

        try {
            $result = app(ProvisioningService::class)->provision($order);

            return ['state' => $result->state];
        } catch (Throwable $exception) {
            return ['error' => $exception::class.': '.$exception->getMessage()];
        }
    });

    $fresh = Order::query()->findOrFail($orderId);

    expect(FakeProviderServer::query()->count())->toBe(1)
        ->and(Server::query()->count())->toBe(1)
        ->and(Subscription::query()->count())->toBe(1)
        ->and($fresh->status)->toBe(OrderStatus::Provisioned)
        // One token, and the machine carries it.
        ->and(FakeProviderServer::query()->firstOrFail()->provisioning_token)
        ->toBe($fresh->provisioning_uuid)
        ->and(Server::query()->firstOrFail()->provisioning_uuid)->toBe($fresh->provisioning_uuid)
        // No worker crashed.
        ->and(array_filter(array_column($results, 'error')))->toBe([]);

    // And the service period was established exactly once.
    expect(Subscription::query()->firstOrFail()->periodSeconds())->toBe(2_592_000);
});

it('lets exactly one worker claim the order and the rest reuse its token', function (): void {
    $order = $this->floor->paidOrder();
    $orderId = (int) $order->getKey();

    // Only the claim, so the compare-and-set is measured on its own.
    $results = ForkedWorkers::run(6, function () use ($orderId): array {
        $order = Order::query()->findOrFail($orderId);
        $before = $order->status->value;

        $prepared = app(ProvisioningService::class)->prepare($order);

        return [
            'saw' => $before,
            'token' => $prepared?->provisioning_uuid,
        ];
    });

    $tokens = array_values(array_unique(array_filter(array_column($results, 'token'))));
    $fresh = Order::query()->findOrFail($orderId);

    expect($tokens)->toHaveCount(1)
        ->and($tokens[0])->toBe($fresh->provisioning_uuid)
        // Every worker that read the order as paid still ends up agreeing on
        // one token: the loser of the compare-and-set reuses the winner's
        // rather than generating a second intended machine.
        ->and(count(array_filter($results, static fn (array $r): bool => ($r['saw'] ?? null) === 'paid')))
        ->toBeGreaterThan(1);
});

it('persists one server when workers race to store the same remote machine', function (): void {
    $order = $this->floor->paidOrder();
    $orderId = (int) $order->getKey();

    // The machine already exists; only the local write is contended.
    $prepared = app(ProvisioningService::class)->prepare($order);
    Simulator::plain()->createServer(new CreateServerRequest(
        provisioningToken: (string) $prepared->provisioning_uuid,
        providerPlanId: FakeCatalog::PLAN_SMALL,
        providerLocationId: FakeCatalog::LOCATION_PRIMARY,
        providerImageId: FakeCatalog::IMAGE_UBUNTU,
        name: 'cbm-already-built',
    ));

    ForkedWorkers::run(4, function () use ($orderId): array {
        $order = Order::query()->findOrFail($orderId);

        try {
            $result = app(ReconciliationService::class)->reconcile($order);

            return ['state' => $result->state];
        } catch (Throwable $exception) {
            return ['error' => $exception::class];
        }
    });

    expect(Server::query()->count())->toBe(1)
        ->and(Subscription::query()->count())->toBe(1)
        ->and(FakeProviderServer::query()->count())->toBe(1)
        ->and(Order::query()->findOrFail($orderId)->status)->toBe(OrderStatus::Provisioned);
});

it('makes no second provider create while a worker holds the lock', function (): void {
    $order = $this->floor->paidOrder();
    $orderId = (int) $order->getKey();

    // Every worker provisions; the lock plus the token pre-check between them
    // must leave exactly one create call.
    ForkedWorkers::run(5, function () use ($orderId): array {
        $order = Order::query()->findOrFail($orderId);

        try {
            $result = app(ProvisioningService::class)->provision($order);

            return ['state' => $result->state];
        } catch (Throwable $exception) {
            return ['error' => $exception::class];
        }
    });

    // The simulator's unique index on the token is the final guarantee, and it
    // holds: one remote machine, whatever the workers did.
    expect(FakeProviderServer::query()->count())->toBe(1)
        ->and(Server::query()->count())->toBe(1)
        ->and(Subscription::query()->count())->toBe(1);
});

it('lets a worker and the sweeper race without producing two of anything', function (): void {
    $order = $this->floor->paidOrder();
    $orderId = (int) $order->getKey();

    $prepared = app(ProvisioningService::class)->prepare($order);
    Simulator::plain()->createServer(new CreateServerRequest(
        provisioningToken: (string) $prepared->provisioning_uuid,
        providerPlanId: FakeCatalog::PLAN_SMALL,
        providerLocationId: FakeCatalog::LOCATION_PRIMARY,
        providerImageId: FakeCatalog::IMAGE_UBUNTU,
        name: 'cbm-contended',
    ));

    // Half provision, half reconcile — the real overlap in production, where a
    // retried job and the five-minute sweeper meet on the same order.
    ForkedWorkers::run(6, function (int $index) use ($orderId): array {
        $order = Order::query()->findOrFail($orderId);

        try {
            $result = $index % 2 === 0
                ? app(ProvisioningService::class)->provision($order)
                : app(ReconciliationService::class)->reconcile($order);

            return ['state' => $result->state];
        } catch (Throwable $exception) {
            return ['error' => $exception::class];
        }
    });

    $fresh = Order::query()->findOrFail($orderId);

    expect(FakeProviderServer::query()->count())->toBe(1)
        ->and(Server::query()->count())->toBe(1)
        ->and(Subscription::query()->count())->toBe(1)
        ->and($fresh->status)->toBe(OrderStatus::Provisioned)
        ->and(Server::query()->firstOrFail()->provisioning_uuid)->toBe($fresh->provisioning_uuid);
});

it('keeps the wallet ledger exact through concurrent provisioning', function (): void {
    $order = $this->floor->paidOrder();
    $orderId = (int) $order->getKey();
    $customerId = (int) $this->floor->customer->getKey();

    ForkedWorkers::run(4, function () use ($orderId): array {
        try {
            app(ProvisioningService::class)->provision(Order::query()->findOrFail($orderId));

            return ['ok' => true];
        } catch (Throwable $exception) {
            return ['error' => $exception::class];
        }
    });

    // The invariant that must survive every phase.
    expect((int) User::query()->findOrFail($customerId)->wallet_balance_toman)
        ->toBe((int) WalletTransaction::query()->where('user_id', $customerId)->sum('amount_toman'));
});

it('numbers concurrent attempts without collision', function (): void {
    $order = $this->floor->paidOrder();
    $orderId = (int) $order->getKey();

    ForkedWorkers::run(4, function () use ($orderId): array {
        try {
            app(ProvisioningService::class)->provision(Order::query()->findOrFail($orderId));

            return ['ok' => true];
        } catch (Throwable $exception) {
            return ['error' => $exception::class];
        }
    });

    $numbers = ProvisioningAttempt::query()->where('order_id', $orderId)->pluck('attempt_no')->all();

    // The unique index decides; nothing counted in PHP.
    expect($numbers)->toBe(array_values(array_unique($numbers)));
});
