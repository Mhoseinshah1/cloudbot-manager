<?php

declare(strict_types=1);

use App\Authorization\RoleProvisioner;
use App\Enums\OrderStatus;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\OutboxMessage;
use App\Models\WalletTransaction;
use App\Orders\Data\PurchaseIntent;
use App\Orders\Exceptions\OrderNotPlaceable;
use App\Orders\OrderService;
use App\Provisioning\Data\ProvisioningResult;
use App\Provisioning\ProvisioningService;
use Illuminate\Support\Str;
use Tests\Support\Provisioning\ProvisioningFloor;
use Tests\Support\Provisioning\Simulator;

/**
 * Replaying one purchase intent after a crash between its durable steps.
 *
 * A purchase is three separate commits — the order, the request for payment,
 * the payment itself — because no two of them can be made one transaction. A
 * worker can die between any pair, and the same Telegram update, the same
 * queued job or the same impatient customer then arrives carrying the identical
 * intent.
 *
 * Repeating the steps does not replay the intent. `place()` correctly returns
 * the order it already made, but asking the state machine to move an
 * awaiting-payment order to awaiting payment is a transition it does not have,
 * and asking a paid one is worse. So the customer whose worker died at the
 * wrong instant was stranded with an order nobody would ever pay for, or told
 * their successful purchase had failed.
 *
 * What has to be replayed is the intent, not the sequence: read where the order
 * actually got to, and perform only the step that is missing.
 */
beforeEach(function (): void {
    app(RoleProvisioner::class)->sync();

    $this->floor = ProvisioningFloor::open();
    $this->orders = app(OrderService::class);
    $this->scripted = Simulator::script();

    // One durable purchase identity, reused by every replay below exactly as
    // the buy flow reuses the one it generated when the conversation began.
    $this->intent = new PurchaseIntent(
        $this->floor->customer,
        $this->floor->price,
        ProvisioningFloor::AUP_VERSION,
        true,
        'purchase:'.(string) Str::uuid(),
    );
});

/**
 * What one completed purchase must look like, however many replays produced it.
 */
function expectExactlyOnePurchase(Order $order): void
{
    expect(Order::query()->count())->toBe(1)
        ->and($order->status)->toBe(OrderStatus::Paid)
        // One debit for one purchase. The wallet's idempotency key is what
        // guarantees it, and this is what proves the key was actually reached.
        ->and(WalletTransaction::query()->where('type', 'debit')->count())->toBe(1)
        ->and(Invoice::query()->count())->toBe(1)
        ->and(OutboxMessage::query()
            ->where('deduplication_key', OrderService::provisioningRequestKey($order))
            ->count())->toBe(1);
}

it('resumes a purchase that crashed after the order was created', function (): void {
    // A. Committed, and then the worker died before asking for payment.
    $first = $this->orders->place($this->intent);

    expect($first->status)->toBe(OrderStatus::Pending);

    // The identical intent arrives again.
    $replayed = $this->orders->place($this->intent);
    $paid = $this->orders->settleFromWallet($replayed, $this->floor->customer);

    expect($replayed->getKey())->toBe($first->getKey());
    expectExactlyOnePurchase($paid);
});

it('resumes a purchase that crashed after payment was requested', function (): void {
    // B. The case the finding names. `awaiting_payment` has no transition to
    // itself, so repeating the sequence used to strand this order unpaid
    // forever with the customer's money untouched and nobody to notice.
    $first = $this->orders->place($this->intent);
    $this->orders->awaitPayment($first);

    expect($first->fresh()->status)->toBe(OrderStatus::AwaitingPayment);

    $replayed = $this->orders->place($this->intent);
    $paid = $this->orders->settleFromWallet($replayed, $this->floor->customer);

    expectExactlyOnePurchase($paid);
});

it('acknowledges a purchase that crashed after the money moved', function (): void {
    // C. The worse half. The customer has been charged, the invoice exists and
    // provisioning has been asked for; repeating the sequence used to throw,
    // so the replay reported a failure for a purchase that had succeeded.
    $first = $this->orders->place($this->intent);
    $funded = $this->orders->payFromWallet($this->orders->awaitPayment($first), $this->floor->customer);

    $balance = (int) $this->floor->customer->fresh()->wallet_balance_toman;

    $replayed = $this->orders->place($this->intent);
    $resumed = $this->orders->settleFromWallet($replayed, $this->floor->customer);

    expect($resumed->getKey())->toBe($funded->getKey());
    expectExactlyOnePurchase($resumed);

    // Not one Toman more.
    expect((int) $this->floor->customer->fresh()->wallet_balance_toman)->toBe($balance);
});

it('acknowledges a purchase whose provisioning is already under way', function (): void {
    // D. The provisioning intent exists and a worker has taken it. A late
    // replay of the customer's confirmation must not disturb any of that.
    $first = $this->orders->place($this->intent);
    $funded = $this->orders->payFromWallet($this->orders->awaitPayment($first), $this->floor->customer);

    expect(app(ProvisioningService::class)->provision($funded)->state)
        ->toBe(ProvisioningResult::Provisioned);

    $balance = (int) $this->floor->customer->fresh()->wallet_balance_toman;

    $replayed = $this->orders->place($this->intent);
    $resumed = $this->orders->settleFromWallet($replayed, $this->floor->customer);

    expect($resumed->status)->toBe(OrderStatus::Provisioned)
        ->and(Order::query()->count())->toBe(1)
        ->and(WalletTransaction::query()->where('type', 'debit')->count())->toBe(1)
        ->and(Invoice::query()->count())->toBe(1)
        ->and(App\Models\Server::query()->count())->toBe(1)
        ->and($this->scripted->callCount('createServer'))->toBe(1)
        ->and((int) $this->floor->customer->fresh()->wallet_balance_toman)->toBe($balance);
});

it('is idempotent across several replays in a row', function (): void {
    $order = $this->orders->place($this->intent);

    foreach (range(1, 4) as $ignored) {
        $order = $this->orders->settleFromWallet(
            $this->orders->place($this->intent),
            $this->floor->customer,
        );
    }

    expectExactlyOnePurchase($order);
});

it('refuses to revive an order the business has already closed', function (): void {
    $order = $this->orders->place($this->intent);
    $this->orders->cancel($order, $this->floor->customer);

    // A stale keyboard, a re-delivered update, a customer's second tap. None of
    // them reopens a cancelled purchase.
    expect(fn () => $this->orders->settleFromWallet($order->fresh(), $this->floor->customer))
        ->toThrow(OrderNotPlaceable::class);

    expect($order->fresh()->status)->toBe(OrderStatus::Cancelled)
        ->and(WalletTransaction::query()->where('type', 'debit')->count())->toBe(0);
});

it('refuses to settle somebody else\'s order', function (): void {
    $order = $this->orders->place($this->intent);
    $stranger = App\Models\User::factory()->fromTelegram()->create();

    expect(fn () => $this->orders->settleFromWallet($order, $stranger))
        ->toThrow(OrderNotPlaceable::class);

    expect($order->fresh()->status)->toBe(OrderStatus::Pending);
});
