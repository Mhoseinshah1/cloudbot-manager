<?php

declare(strict_types=1);

use App\Authorization\RoleProvisioner;
use App\Cloud\Fake\Models\FakeProviderServer;
use App\Enums\OrderStatus;
use App\Enums\WalletTransactionType;
use App\Models\Order;
use App\Models\Server;
use App\Models\Subscription;
use App\Models\WalletTransaction;
use App\Provisioning\Data\ProvisioningResult;
use App\Provisioning\ProvisioningService;
use App\Provisioning\ReconciliationService;
use Illuminate\Support\Facades\DB;
use Tests\Support\Concurrency\ForkedWorkers;
use Tests\Support\Provisioning\ProvisioningFloor;
use Tests\Support\Provisioning\Simulator;

/**
 * Reconciliation running while a create is genuinely in flight.
 *
 * The most expensive race this system can lose. A provisioning worker reserves
 * the last permitted create attempt, commits it, and calls the provider; the
 * call is still open when a sweep looks at the same order. The sweep asks the
 * provider what carries the token and is told nothing does — truthfully, for
 * another second — reads the create budget as spent, and concludes a confirmed
 * absence. It refunds. Then the create returns a machine.
 *
 * Live server, full refund, and no way to take either back.
 *
 * Nothing about that is reproducible in a sequential test: it needs two real
 * processes, one of them parked inside a provider call, both against one
 * PostgreSQL database and the real Redis lock topology.
 */
function resetReconcileConcurrencyTables(): void
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
    resetReconcileConcurrencyTables();
    app(RoleProvisioner::class)->sync();

    $this->floor = ProvisioningFloor::open(walletBalance: 20_000_000);
});

afterEach(function (): void {
    resetReconcileConcurrencyTables();
});

it('never refunds an order while its last permitted create is still in flight', function (): void {
    // One attempt, so the budget is exhausted the instant it is reserved. That
    // is the arrangement in which the old code's absence check turns into a
    // refund, and it is a real configuration rather than a contrivance: the
    // final attempt of any budget looks exactly like this.
    config(['cloudbot.provisioning.max_attempts' => 1]);

    $order = $this->floor->paidOrder();
    $orderId = (int) $order->getKey();
    $balanceBefore = (int) $this->floor->customer->fresh()->wallet_balance_toman;

    $results = ForkedWorkers::run(2, function (int $index) use ($orderId): array {
        if ($index === 0) {
            // The provisioning worker. It blocks inside createServer, after the
            // token is committed, after the attempt is reserved and after the
            // create-stage attempt row is written — so for the duration of the
            // block the provider genuinely holds nothing for this token and the
            // budget genuinely reads as spent. Both facts are true, and the
            // conclusion drawn from them together used to be a refund.
            Simulator::script()->beforeCreate(static function (): void {
                usleep(2_500_000);
            });

            $result = app(ProvisioningService::class)->provision(
                Order::query()->findOrFail($orderId),
            );

            return ['role' => 'provision', 'state' => $result->state];
        }

        // The sweep. Enters while the create above is parked.
        usleep(900_000);

        $result = app(ReconciliationService::class)->reconcile(
            Order::query()->findOrFail($orderId),
        );

        return [
            'role' => 'reconcile',
            'state' => $result->state,
            'may_dispatch' => $result->mayDispatch,
        ];
    });

    $provisioner = $results[0];
    $reconciler = $results[1];

    $fresh = Order::query()->findOrFail($orderId);
    $refunds = WalletTransaction::query()
        ->where('type', WalletTransactionType::Refund->value)
        ->count();

    expect($provisioner['error'])->toBeNull()
        ->and($reconciler['error'])->toBeNull()
        // The sweep found the lock held and said so, which is the only honest
        // answer available to it: the worker holding the lock is the one
        // finding out what the provider did.
        ->and($reconciler['state'])->toBe(ProvisioningResult::Contended)
        // And it queued nothing. A contended sweep must not put create-capable
        // work behind the worker it could not get past.
        ->and($reconciler['may_dispatch'])->toBeFalse()
        // Not one Toman went back.
        ->and($refunds)->toBe(0)
        ->and((int) $this->floor->customer->fresh()->wallet_balance_toman)->toBe($balanceBefore)
        // The create completed and produced exactly one machine.
        ->and($provisioner['state'])->toBe(ProvisioningResult::Provisioned)
        ->and(FakeProviderServer::query()->count())->toBe(1)
        ->and(Server::query()->count())->toBe(1)
        ->and(Subscription::query()->count())->toBe(1)
        ->and($fresh->status)->toBe(OrderStatus::Provisioned)
        // Same durable token throughout, and the machine carries it.
        ->and($fresh->provisioning_uuid)->not->toBeNull()
        ->and(FakeProviderServer::query()->firstOrFail()->provisioning_token)
        ->toBe($fresh->provisioning_uuid)
        // One create was reserved and one was spent. Never max + 1.
        ->and((int) $fresh->attempts)->toBe(1);
});

it('makes no provider read at all when it cannot take the order lock', function (): void {
    $order = $this->floor->paidOrder();
    $orderId = (int) $order->getKey();

    $results = ForkedWorkers::run(2, function (int $index) use ($orderId): array {
        if ($index === 0) {
            Simulator::script()->beforeCreate(static function (): void {
                usleep(2_000_000);
            });

            app(ProvisioningService::class)->provision(Order::query()->findOrFail($orderId));

            return ['role' => 'provision'];
        }

        usleep(700_000);

        $scripted = Simulator::script();

        $result = app(ReconciliationService::class)->reconcile(
            Order::query()->findOrFail($orderId),
        );

        return [
            'role' => 'reconcile',
            'state' => $result->state,
            // Every provider call this process made. A contended sweep should
            // have made none: it may not even look, because looking is what
            // produces the observation it would then reason wrongly from.
            'calls' => $scripted->calls,
        ];
    });

    expect($results[1]['error'])->toBeNull()
        ->and($results[1]['state'])->toBe(ProvisioningResult::Contended)
        ->and($results[1]['calls'])->toBe([]);
});
