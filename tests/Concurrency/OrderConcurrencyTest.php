<?php

declare(strict_types=1);

use App\Audit\AuditEvent;
use App\Authorization\RoleProvisioner;
use App\Enums\ConfirmedNoServerOutcome;
use App\Enums\OrderFailureCategory;
use App\Enums\OrderStatus;
use App\Models\AuditLog;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\OutboxMessage;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Orders\Data\PurchaseIntent;
use App\Orders\OrderService;
use App\Orders\OrderStateMachine;
use App\Orders\RefundService;
use App\Outbox\OutboxTopic;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\Concurrency\ForkedWorkers;
use Tests\Support\Orders\SalesFloor;

/**
 * Two of everything, at the same instant, in genuinely separate processes.
 *
 * These are the races a sequential replay cannot produce: both processes read
 * the same order before either has written anything. That is when a duplicate
 * order gets created, a customer gets charged twice, or a refund gets paid out
 * twice — and it is the only way to find out whether the unique constraints and
 * the compare-and-set actually hold.
 */
function resetOrderTables(): void
{
    DB::statement(
        'TRUNCATE outbox_messages, wallet_transactions, invoices, payments, orders,
         product_location_prices, products, provider_images, provider_plans, provider_locations,
         provider_credentials, providers, exchange_rates, settings, audit_logs RESTART IDENTITY CASCADE'
    );
    DB::table('users')->delete();
}

beforeEach(function (): void {
    resetOrderTables();
    app(RoleProvisioner::class)->sync();

    $this->floor = SalesFloor::open();
});

afterEach(function (): void {
    resetOrderTables();
});

it('creates exactly one order when the same key arrives twice at once', function (): void {
    $key = (string) Str::uuid();
    $customerId = $this->floor->customer->id;
    $priceId = $this->floor->catalog->price->id;

    $results = ForkedWorkers::run(4, function () use ($key, $customerId, $priceId): array {
        $customer = User::query()->findOrFail($customerId);
        $price = App\Models\ProductLocationPrice::query()->findOrFail($priceId);

        try {
            $order = app(OrderService::class)->place(new PurchaseIntent(
                $customer, $price, SalesFloor::AUP_VERSION, true, $key,
            ));

            return ['order_id' => $order->getKey()];
        } catch (Throwable $exception) {
            return ['error' => $exception::class];
        }
    });

    $orderIds = array_values(array_unique(array_filter(array_column($results, 'order_id'))));

    expect(Order::query()->count())->toBe(1)
        // Every worker that succeeded got the same order back.
        ->and($orderIds)->toHaveCount(1)
        ->and(Order::query()->sole()->getKey())->toBe($orderIds[0]);
});

it('debits once when one order is paid concurrently', function (): void {
    $orders = app(OrderService::class);
    $order = $orders->awaitPayment($orders->place(new PurchaseIntent(
        $this->floor->customer, $this->floor->catalog->price,
        SalesFloor::AUP_VERSION, true, (string) Str::uuid(),
    )));

    $orderId = $order->id;
    $customerId = $this->floor->customer->id;
    $opening = $this->floor->customer->fresh()->wallet_balance_toman;

    ForkedWorkers::run(4, function () use ($orderId, $customerId): array {
        $customer = User::query()->findOrFail($customerId);
        $order = Order::query()->findOrFail($orderId);

        try {
            return ['status' => app(OrderService::class)->payFromWallet($order, $customer)->status->value];
        } catch (Throwable $exception) {
            return ['error' => $exception::class];
        }
    });

    $fresh = Order::query()->findOrFail($orderId);
    $debits = WalletTransaction::query()->where('idempotency_key', $fresh->paymentIdempotencyKey())->get();

    expect($fresh->status)->toBe(OrderStatus::Paid)
        ->and($debits)->toHaveCount(1)
        ->and($debits->first()->amount_toman)->toBe(-1_500_000)
        ->and(Invoice::query()->where('order_id', $orderId)->count())->toBe(1)
        ->and(User::query()->findOrFail($customerId)->wallet_balance_toman)->toBe($opening - 1_500_000);

    expect(User::query()->findOrFail($customerId)->wallet_balance_toman)
        ->toBe((int) WalletTransaction::query()->where('user_id', $customerId)->sum('amount_toman'));
});

it('refunds once when the same failure is confirmed concurrently', function (): void {
    $orders = app(OrderService::class);
    $order = $orders->payFromWallet(
        $orders->awaitPayment($orders->place(new PurchaseIntent(
            $this->floor->customer, $this->floor->catalog->price,
            SalesFloor::AUP_VERSION, true, (string) Str::uuid(),
        ))),
        $this->floor->customer,
    );

    $orderId = $order->id;
    $customerId = $this->floor->customer->id;
    $charged = User::query()->findOrFail($customerId)->wallet_balance_toman;

    ForkedWorkers::run(4, function () use ($orderId): array {
        try {
            $refunded = app(RefundService::class)->refundConfirmedFailure(
                Order::query()->findOrFail($orderId),
                ConfirmedNoServerOutcome::ProviderRejectedNoServer,
            );

            return ['status' => $refunded->status->value];
        } catch (Throwable $exception) {
            return ['error' => $exception::class];
        }
    });

    $fresh = Order::query()->findOrFail($orderId);

    expect($fresh->status)->toBe(OrderStatus::Refunded)
        ->and(WalletTransaction::query()->where('idempotency_key', $fresh->refundIdempotencyKey())->count())
        ->toBe(1)
        // Scoped to the refund topic: a paid order also promises to be
        // provisioned, and what this proves is one refund promise.
        ->and(OutboxMessage::query()->where('topic', OutboxTopic::OrderRefunded)->count())->toBe(1)
        ->and(User::query()->findOrFail($customerId)->wallet_balance_toman)
        ->toBe($charged + 1_500_000);

    expect(User::query()->findOrFail($customerId)->wallet_balance_toman)
        ->toBe((int) WalletTransaction::query()->where('user_id', $customerId)->sum('amount_toman'));
});

it('lets only one competing transition win from the same expected state', function (): void {
    $orders = app(OrderService::class);
    $order = $orders->payFromWallet(
        $orders->awaitPayment($orders->place(new PurchaseIntent(
            $this->floor->customer, $this->floor->catalog->price,
            SalesFloor::AUP_VERSION, true, (string) Str::uuid(),
        ))),
        $this->floor->customer,
    );

    $orderId = $order->id;

    // Every worker reads the order as paid and tries to claim it.
    $results = ForkedWorkers::run(6, function () use ($orderId): array {
        $order = Order::query()->findOrFail($orderId);

        try {
            app(OrderStateMachine::class)->transition($order, OrderStatus::Paid, OrderStatus::Provisioning);

            return ['won' => true];
        } catch (Throwable) {
            return ['won' => false];
        }
    });

    $winners = array_filter(array_column($results, 'won'));

    expect($winners)->toHaveCount(1)
        ->and(Order::query()->findOrFail($orderId)->status)->toBe(OrderStatus::Provisioning);
});

it('parks an uncertain outcome once when it is reported concurrently', function (): void {
    $orders = app(OrderService::class);
    $order = app(OrderStateMachine::class)->transition(
        $orders->payFromWallet(
            $orders->awaitPayment($orders->place(new PurchaseIntent(
                $this->floor->customer, $this->floor->catalog->price,
                SalesFloor::AUP_VERSION, true, (string) Str::uuid(),
            ))),
            $this->floor->customer,
        ),
        OrderStatus::Paid,
        OrderStatus::Provisioning,
    );

    $orderId = $order->id;
    $customerId = $this->floor->customer->id;
    $charged = User::query()->findOrFail($customerId)->wallet_balance_toman;

    $results = ForkedWorkers::run(4, function () use ($orderId): array {
        try {
            $parked = app(RefundService::class)->recordUncertainResult(
                Order::query()->findOrFail($orderId),
                'provider create timed out',
            );

            return ['status' => $parked->status->value];
        } catch (Throwable $exception) {
            return ['error' => $exception::class];
        }
    });

    // Every worker agrees on the outcome; none of them fails, and none of them
    // turns an unknown result into a refundable failure.
    expect(array_column($results, 'status'))
        ->toBe(array_fill(0, 4, OrderStatus::NeedsAttention->value));

    $fresh = Order::query()->findOrFail($orderId);

    expect($fresh->status)->toBe(OrderStatus::NeedsAttention)
        ->and($fresh->failure_category)->toBe(OrderFailureCategory::UncertainResult)
        // No refund promised, no money moved: a human decides next.
        ->and(WalletTransaction::query()->where('idempotency_key', $fresh->refundIdempotencyKey())->count())
        ->toBe(0)
        ->and(OutboxMessage::query()->where('topic', OutboxTopic::OrderRefunded)->count())->toBe(0)
        ->and(User::query()->findOrFail($customerId)->wallet_balance_toman)->toBe($charged)
        // Recorded exactly once, however many workers reported it.
        ->and(AuditLog::query()->where('event', AuditEvent::OrderNeedsAttention)->count())->toBe(1);
});
