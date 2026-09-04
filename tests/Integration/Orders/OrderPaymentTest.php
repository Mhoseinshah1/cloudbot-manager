<?php

declare(strict_types=1);

use App\Audit\AuditEvent;
use App\Authorization\RoleProvisioner;
use App\Enums\InvoiceType;
use App\Enums\OrderRefusalReason;
use App\Enums\OrderStatus;
use App\Enums\WalletTransactionType;
use App\Models\AuditLog;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Orders\Data\PurchaseIntent;
use App\Orders\Exceptions\OrderNotPlaceable;
use App\Orders\OrderService;
use App\Wallet\Exceptions\InsufficientBalance;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Support\Orders\SalesFloor;

beforeEach(function (): void {
    app(RoleProvisioner::class)->sync();

    $this->orders = app(OrderService::class);
    $this->floor = SalesFloor::open();
});

/** An order placed and moved to awaiting_payment, ready to be paid. */
function awaitingOrder(?DateTimeInterface $expiresAt = null): Order
{
    $order = test()->orders->place(new PurchaseIntent(
        user: test()->floor->customer,
        locationPrice: test()->floor->catalog->price,
        acceptedAupVersion: SalesFloor::AUP_VERSION,
        aupAccepted: true,
        idempotencyKey: (string) Str::uuid(),
    ));

    return test()->orders->awaitPayment($order, $expiresAt);
}

it('moves an unpaid order into awaiting payment', function (): void {
    $order = awaitingOrder();

    expect($order->status)->toBe(OrderStatus::AwaitingPayment)
        ->and($order->awaiting_payment_expires_at)->toBeNull()
        ->and(AuditLog::query()->where('event', AuditEvent::OrderAwaitingPayment)->count())->toBe(1);
});

it('records an explicit payment deadline when one is given', function (): void {
    // No default. The specification names no timeout, and inventing one would
    // expire real orders on a rule nobody agreed to.
    $deadline = now()->addMinutes(30);

    expect(awaitingOrder($deadline)->awaiting_payment_expires_at->timestamp)
        ->toBe($deadline->timestamp);
});

it('pays an order from the wallet', function (): void {
    $before = $this->floor->customer->fresh()->wallet_balance_toman;

    $paid = $this->orders->payFromWallet(awaitingOrder(), $this->floor->customer);

    expect($paid->status)->toBe(OrderStatus::Paid)
        ->and($this->floor->customer->fresh()->wallet_balance_toman)->toBe($before - 1_500_000);
});

it('debits exactly the order total, and no more', function (): void {
    $paid = $this->orders->payFromWallet(awaitingOrder(), $this->floor->customer);

    $debit = WalletTransaction::query()
        ->where('idempotency_key', $paid->paymentIdempotencyKey())
        ->sole();

    expect($debit->type)->toBe(WalletTransactionType::Debit)
        ->and($debit->amount_toman)->toBe(-$paid->total_toman)->toBe(-1_500_000)
        ->and($debit->amount_toman)->toBeInt();
});

it('points the ledger entry at the order', function (): void {
    $paid = $this->orders->payFromWallet(awaitingOrder(), $this->floor->customer);

    $debit = WalletTransaction::query()->where('idempotency_key', $paid->paymentIdempotencyKey())->sole();

    expect($debit->reference_type)->toBe((new Order)->getMorphClass())
        ->and($debit->reference_id)->toBe((string) $paid->getKey());
});

it('leaves an order unpaid when the wallet cannot cover it', function (): void {
    $pauper = User::factory()->fromTelegram()->create();
    $order = $this->orders->place(new PurchaseIntent(
        $pauper, $this->floor->catalog->price, SalesFloor::AUP_VERSION, true, (string) Str::uuid(),
    ));
    $order = $this->orders->awaitPayment($order);

    expect(fn () => $this->orders->payFromWallet($order, $pauper))
        ->toThrow(InsufficientBalance::class);

    expect($order->fresh()->status)->toBe(OrderStatus::AwaitingPayment)
        ->and($pauper->fresh()->wallet_balance_toman)->toBe(0)
        ->and(WalletTransaction::query()->where('user_id', $pauper->id)->count())->toBe(0)
        ->and(Invoice::query()->where('order_id', $order->id)->count())->toBe(0);
});

it('refuses to charge an order whose payment window has closed', function (): void {
    $order = awaitingOrder(now()->subMinute());

    try {
        $this->orders->payFromWallet($order, $this->floor->customer);
        $this->fail('An expired order was charged.');
    } catch (OrderNotPlaceable $refusal) {
        expect($refusal->reason)->toBe(OrderRefusalReason::PaymentWindowClosed);
    }

    expect(WalletTransaction::query()->where('description', 'Server purchase')->count())->toBe(0);
});

it('refuses to charge an order that is not awaiting payment', function (): void {
    $order = $this->orders->place(new PurchaseIntent(
        $this->floor->customer, $this->floor->catalog->price,
        SalesFloor::AUP_VERSION, true, (string) Str::uuid(),
    ));

    expect($order->status)->toBe(OrderStatus::Pending);

    try {
        $this->orders->payFromWallet($order, $this->floor->customer);
        $this->fail('A pending order was charged.');
    } catch (OrderNotPlaceable $refusal) {
        expect($refusal->reason)->toBe(OrderRefusalReason::NotPayable);
    }
});

it('debits once when payment is replayed', function (): void {
    $order = awaitingOrder();
    $before = $this->floor->customer->fresh()->wallet_balance_toman;

    $first = $this->orders->payFromWallet($order, $this->floor->customer);
    $second = $this->orders->payFromWallet($order->fresh(), $this->floor->customer);

    expect($second->getKey())->toBe($first->getKey())
        ->and($second->status)->toBe(OrderStatus::Paid)
        ->and($this->floor->customer->fresh()->wallet_balance_toman)->toBe($before - 1_500_000)
        ->and(WalletTransaction::query()->where('idempotency_key', $order->paymentIdempotencyKey())->count())
        ->toBe(1);
});

it('does not debit again once the order has moved on to provisioning', function (): void {
    // A replayed payment request arriving late must not charge for a purchase
    // that is already being fulfilled.
    $paid = $this->orders->payFromWallet(awaitingOrder(), $this->floor->customer);
    DB::table('orders')->where('id', $paid->id)->update(['status' => OrderStatus::Provisioning->value]);

    $balance = $this->floor->customer->fresh()->wallet_balance_toman;

    $again = $this->orders->payFromWallet($paid->fresh(), $this->floor->customer);

    expect($again->status)->toBe(OrderStatus::Provisioning)
        ->and($this->floor->customer->fresh()->wallet_balance_toman)->toBe($balance)
        ->and(WalletTransaction::query()->where('idempotency_key', $paid->paymentIdempotencyKey())->count())
        ->toBe(1);
});

it('issues exactly one purchase invoice', function (): void {
    $paid = $this->orders->payFromWallet(awaitingOrder(), $this->floor->customer);
    $this->orders->payFromWallet($paid->fresh(), $this->floor->customer);

    $invoices = Invoice::query()->where('order_id', $paid->id)->get();

    expect($invoices)->toHaveCount(1);

    $invoice = $invoices->first();

    expect($invoice->user_id)->toBe($paid->user_id)
        ->and($invoice->order_id)->toBe($paid->getKey())
        ->and($invoice->amount_toman)->toBe($paid->total_toman)
        ->and($invoice->type)->toBe(InvoiceType::ServerPurchase)
        ->and($invoice->number)->toStartWith('INV-O')
        ->and($invoice->lineItemTotal())->toBe($paid->total_toman)
        ->and($invoice->line_items[0]['description'])->toContain($paid->order_number)
        ->and($invoice->issued_at->timezone->getName())->toBe('UTC');
});

it('carries the order snapshot onto the invoice rather than repricing', function (): void {
    $paid = $this->orders->payFromWallet(awaitingOrder(), $this->floor->customer);

    $invoice = Invoice::query()->where('order_id', $paid->id)->sole();

    expect($invoice->pricing_snapshot['order_number'])->toBe($paid->order_number)
        ->and($invoice->pricing_snapshot['cost']['exchange_rate'])->toBe('92345.12345678')
        ->and($invoice->pricing_snapshot['pricing']['selling_price_toman'])->toBe(1_500_000);
});

it('keeps the wallet top-up invoice series separate', function (): void {
    // Phase 4 behaviour, unchanged: a top-up invoice is a different document
    // with a different number series.
    $paid = $this->orders->payFromWallet(awaitingOrder(), $this->floor->customer);

    $numbers = Invoice::query()->pluck('number');

    expect($numbers->filter(fn (string $n): bool => str_starts_with($n, 'INV-O')))->toHaveCount(1)
        ->and($numbers->unique())->toHaveCount($numbers->count());
});

it('refuses to let one customer pay another customer\'s order', function (): void {
    $order = awaitingOrder();
    $stranger = User::factory()->fromTelegram()->create();
    app(App\Wallet\WalletService::class)->credit($stranger, 5_000_000, (string) Str::uuid(), 'Wallet top-up');

    try {
        $this->orders->payFromWallet($order, $stranger);
        $this->fail('A stranger paid for someone else\'s order.');
    } catch (OrderNotPlaceable $refusal) {
        expect($refusal->reason)->toBe(OrderRefusalReason::NotTheOwner);
    }

    expect($order->fresh()->status)->toBe(OrderStatus::AwaitingPayment)
        ->and($stranger->fresh()->wallet_balance_toman)->toBe(5_000_000);
});

it('refuses to let a suspended customer pay', function (): void {
    $order = awaitingOrder();
    $this->floor->customer->forceFill(['status' => App\Enums\UserStatus::Suspended])->save();

    expect(fn () => $this->orders->payFromWallet($order, $this->floor->customer->fresh()))
        ->toThrow(OrderNotPlaceable::class);

    expect($order->fresh()->status)->toBe(OrderStatus::AwaitingPayment);
});

it('scopes a customer lookup to their own orders', function (): void {
    // Expressed in the query, not checked after loading everyone's.
    $mine = awaitingOrder();
    $stranger = User::factory()->fromTelegram()->create();

    expect($this->orders->findForCustomer($this->floor->customer, $mine->id)->getKey())->toBe($mine->getKey())
        ->and($this->orders->findForCustomer($stranger, $mine->id))->toBeNull();
});

it('refuses to let a stranger cancel an order', function (): void {
    $order = awaitingOrder();
    $stranger = User::factory()->fromTelegram()->create();

    expect(fn () => $this->orders->cancel($order, $stranger))->toThrow(OrderNotPlaceable::class);
    expect($order->fresh()->status)->toBe(OrderStatus::AwaitingPayment);
});

it('lets the owner cancel an unpaid order', function (): void {
    $cancelled = $this->orders->cancel(awaitingOrder(), $this->floor->customer);

    expect($cancelled->status)->toBe(OrderStatus::Cancelled)
        ->and(AuditLog::query()->where('event', AuditEvent::OrderCancelled)->count())->toBe(1);
});

it('expires an unpaid order without moving money', function (): void {
    $order = awaitingOrder(now()->subMinute());
    $before = $this->floor->customer->fresh()->wallet_balance_toman;

    $expired = $this->orders->expire($order);

    expect($expired->status)->toBe(OrderStatus::Expired)
        ->and($this->floor->customer->fresh()->wallet_balance_toman)->toBe($before)
        ->and(Invoice::query()->where('order_id', $order->id)->count())->toBe(0)
        ->and(WalletTransaction::query()->where('reference_id', (string) $order->id)->count())->toBe(0)
        ->and(AuditLog::query()->where('event', AuditEvent::OrderExpired)->count())->toBe(1);
});

it('audits the payment once', function (): void {
    $order = awaitingOrder();

    $this->orders->payFromWallet($order, $this->floor->customer);
    $this->orders->payFromWallet($order->fresh(), $this->floor->customer);

    expect(AuditLog::query()->where('event', AuditEvent::OrderPaid)->count())->toBe(1);
});

it('makes no network request while paying', function (): void {
    Http::preventStrayRequests();

    $this->orders->payFromWallet(awaitingOrder(), $this->floor->customer);

    Http::assertNothingSent();
});

it('keeps the balance equal to the ledger after a purchase', function (): void {
    $this->orders->payFromWallet(awaitingOrder(), $this->floor->customer);

    $ledger = (int) WalletTransaction::query()
        ->where('user_id', $this->floor->customer->id)->sum('amount_toman');

    expect($this->floor->customer->fresh()->wallet_balance_toman)->toBe($ledger);
});
