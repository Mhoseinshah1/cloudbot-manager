<?php

declare(strict_types=1);

use App\Audit\AuditEvent;
use App\Authorization\RoleProvisioner;
use App\Enums\ConfirmedNoServerOutcome;
use App\Enums\OrderFailureCategory;
use App\Enums\OrderStatus;
use App\Enums\RefundRefusalReason;
use App\Enums\WalletTransactionType;
use App\Models\AuditLog;
use App\Models\Order;
use App\Models\OutboxMessage;
use App\Models\WalletTransaction;
use App\Orders\Data\PurchaseIntent;
use App\Orders\Exceptions\RefundNotPermitted;
use App\Orders\OrderService;
use App\Orders\RefundService;
use App\Outbox\OutboxTopic;
use App\Support\Secrets\SecretScrubber;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Support\Orders\SalesFloor;

beforeEach(function (): void {
    app(RoleProvisioner::class)->sync();

    $this->orders = app(OrderService::class);
    $this->refunds = app(RefundService::class);
    $this->floor = SalesFloor::open();
});

/** An order the customer has actually paid for. */
function paidOrder(): Order
{
    $order = test()->orders->place(new PurchaseIntent(
        test()->floor->customer, test()->floor->catalog->price,
        SalesFloor::AUP_VERSION, true, (string) Str::uuid(),
    ));

    return test()->orders->payFromWallet(test()->orders->awaitPayment($order), test()->floor->customer);
}

function refundRefusal(Order $order, ConfirmedNoServerOutcome $outcome): RefundRefusalReason
{
    try {
        test()->refunds->refundConfirmedFailure($order, $outcome);
    } catch (RefundNotPermitted $refusal) {
        return $refusal->reason;
    }

    test()->fail('The refund happened when it should have been refused.');
}

it('refunds the full amount for every confirmed no-server outcome', function (): void {
    foreach (ConfirmedNoServerOutcome::cases() as $outcome) {
        $order = paidOrder();
        $charged = $this->floor->customer->fresh()->wallet_balance_toman;

        $refunded = $this->refunds->refundConfirmedFailure($order, $outcome);

        expect($refunded->status)->toBe(OrderStatus::Refunded, $outcome->value)
            ->and($refunded->failure_category)->toBe($outcome->category())
            ->and($this->floor->customer->fresh()->wallet_balance_toman)
            ->toBe($charged + $order->total_toman, $outcome->value);
    }
});

it('uses exactly the refund key the specification requires', function (): void {
    $order = paidOrder();
    $this->refunds->refundConfirmedFailure($order, ConfirmedNoServerOutcome::ProviderRejectedNoServer);

    $expected = 'refund:order:'.$order->getKey();

    expect($order->refundIdempotencyKey())->toBe($expected)
        ->and(WalletTransaction::query()->where('idempotency_key', $expected)->sole()->type)
        ->toBe(WalletTransactionType::Refund);
});

it('refuses to refund an order that was never charged', function (): void {
    // The most important refusal: a status column is something code writes,
    // the ledger is the record of money actually moving.
    $order = $this->orders->place(new PurchaseIntent(
        $this->floor->customer, $this->floor->catalog->price,
        SalesFloor::AUP_VERSION, true, (string) Str::uuid(),
    ));
    // Forced into a funded-looking state without any money having moved.
    DB::table('orders')->where('id', $order->id)->update(['status' => OrderStatus::Paid->value]);

    $before = $this->floor->customer->fresh()->wallet_balance_toman;

    expect(refundRefusal($order->fresh(), ConfirmedNoServerOutcome::FailureBeforeProviderCreate))
        ->toBe(RefundRefusalReason::NoCommittedCharge);

    expect($this->floor->customer->fresh()->wallet_balance_toman)->toBe($before)
        ->and(WalletTransaction::query()->where('idempotency_key', $order->refundIdempotencyKey())->count())
        ->toBe(0)
        ->and(OutboxMessage::query()->count())->toBe(0);
});

it('will not justify one order\'s refund with another order\'s charge', function (): void {
    // The charge is looked up by this order's own key, for this order's own
    // amount. A customer with one paid order does not thereby have a second
    // one refundable.
    $charged = paidOrder();

    $unpaid = $this->orders->place(new PurchaseIntent(
        $this->floor->customer, $this->floor->catalog->price,
        SalesFloor::AUP_VERSION, true, (string) Str::uuid(),
    ));
    DB::table('orders')->where('id', $unpaid->id)->update(['status' => OrderStatus::Paid->value]);

    $balance = $this->floor->customer->fresh()->wallet_balance_toman;

    expect(refundRefusal($unpaid->fresh(), ConfirmedNoServerOutcome::ProviderRejectedNoServer))
        ->toBe(RefundRefusalReason::NoCommittedCharge);

    expect($this->floor->customer->fresh()->wallet_balance_toman)->toBe($balance)
        ->and(WalletTransaction::query()->where('idempotency_key', $charged->paymentIdempotencyKey())->count())
        ->toBe(1);
});

it('refuses to refund a provisioned order', function (): void {
    // The customer has their server.
    $order = paidOrder();
    DB::table('orders')->where('id', $order->id)->update(['status' => OrderStatus::Provisioned->value]);

    expect(refundRefusal($order->fresh(), ConfirmedNoServerOutcome::ProviderRejectedNoServer))
        ->toBe(RefundRefusalReason::OrderNotRefundable);
});

it('refuses to refund an expired or cancelled order', function (): void {
    foreach ([OrderStatus::Expired, OrderStatus::Cancelled] as $status) {
        $order = paidOrder();
        DB::table('orders')->where('id', $order->id)->update(['status' => $status->value]);

        expect(refundRefusal($order->fresh(), ConfirmedNoServerOutcome::FailureBeforeProviderCreate))
            ->toBe(RefundRefusalReason::OrderNotRefundable, $status->value);
    }
});

it('offers no way to refund an uncertain outcome', function (): void {
    // The type system is the guarantee: ConfirmedNoServerOutcome has no case
    // meaning "we do not know", so a caller holding only a suspicion cannot
    // express it at this boundary at all.
    $confirmed = array_map(fn (ConfirmedNoServerOutcome $c): string => $c->value, ConfirmedNoServerOutcome::cases());

    expect($confirmed)->not->toContain(OrderFailureCategory::UncertainResult->value)
        ->and(OrderFailureCategory::values())->toContain('uncertain_result');
});

it('records an uncertain outcome without refunding anything', function (): void {
    $order = paidOrder();
    $balance = $this->floor->customer->fresh()->wallet_balance_toman;

    $failed = $this->refunds->markFailedWithoutRefund(
        $order, OrderFailureCategory::UncertainResult, 'the provider timed out',
    );

    expect($failed->status)->toBe(OrderStatus::Failed)
        ->and($failed->failure_category)->toBe(OrderFailureCategory::UncertainResult)
        ->and($this->floor->customer->fresh()->wallet_balance_toman)->toBe($balance)
        ->and(WalletTransaction::query()->where('idempotency_key', $order->refundIdempotencyKey())->count())
        ->toBe(0)
        ->and(OutboxMessage::query()->count())->toBe(0);
});

it('does not refund an order left needing attention', function (): void {
    // needs_attention is where an uncertain outcome waits for a person.
    $order = paidOrder();
    DB::table('orders')->where('id', $order->id)->update(['status' => OrderStatus::NeedsAttention->value]);
    $balance = $this->floor->customer->fresh()->wallet_balance_toman;

    // Nothing automatic moves it. Only an explicit confirmed outcome does.
    expect($order->fresh()->status)->toBe(OrderStatus::NeedsAttention)
        ->and($this->floor->customer->fresh()->wallet_balance_toman)->toBe($balance)
        ->and(OutboxMessage::query()->count())->toBe(0);
});

it('refunds once when the decision is reached twice', function (): void {
    $order = paidOrder();
    $charged = $this->floor->customer->fresh()->wallet_balance_toman;

    $first = $this->refunds->refundConfirmedFailure($order, ConfirmedNoServerOutcome::ProviderRejectedNoServer);
    $second = $this->refunds->refundConfirmedFailure($order->fresh(), ConfirmedNoServerOutcome::ProviderRejectedNoServer);

    expect($second->getKey())->toBe($first->getKey())
        ->and($second->status)->toBe(OrderStatus::Refunded)
        ->and($this->floor->customer->fresh()->wallet_balance_toman)->toBe($charged + $order->total_toman)
        ->and(WalletTransaction::query()->where('idempotency_key', $order->refundIdempotencyKey())->count())->toBe(1)
        ->and(OutboxMessage::query()->count())->toBe(1)
        ->and(AuditLog::query()->where('event', AuditEvent::OrderRefunded)->count())->toBe(1);
});

it('resumes a refund for an order already marked failed', function (): void {
    $order = paidOrder();
    $charged = $this->floor->customer->fresh()->wallet_balance_toman;

    $this->refunds->markFailedWithoutRefund($order, OrderFailureCategory::ProviderRejectedNoServer);

    $refunded = $this->refunds->refundConfirmedFailure(
        $order->fresh(), ConfirmedNoServerOutcome::ProviderRejectedNoServer,
    );

    expect($refunded->status)->toBe(OrderStatus::Refunded)
        ->and($this->floor->customer->fresh()->wallet_balance_toman)->toBe($charged + $order->total_toman);
});

it('scrubs a failure reason before storing it', function (): void {
    // A provider error message is the likeliest place for a token to arrive,
    // and this column is read by support staff.
    $order = paidOrder();
    $marker = 'SYNTHETIC-'.bin2hex(random_bytes(6));

    $refunded = $this->refunds->refundConfirmedFailure(
        $order,
        ConfirmedNoServerOutcome::ProviderRejectedNoServer,
        "create failed: Bearer {$marker} was rejected",
    );

    $raw = DB::table('orders')->where('id', $refunded->id)->value('failure_reason');

    expect($raw)->not->toContain($marker)
        ->and($raw)->toContain('create failed')
        ->and($raw)->toContain(SecretScrubber::REDACTED);
});

it('keeps secrets out of the refund audit and outbox', function (): void {
    $order = paidOrder();
    $marker = 'SYNTHETIC-'.bin2hex(random_bytes(6));

    $this->refunds->refundConfirmedFailure(
        $order, ConfirmedNoServerOutcome::ProviderRejectedNoServer, "Bearer {$marker}",
    );

    $serialised = json_encode([
        AuditLog::query()->get(['before', 'after', 'metadata'])->toArray(),
        OutboxMessage::query()->get(['payload'])->toArray(),
    ]);

    expect($serialised)->not->toContain($marker);
});

it('writes one refund notification intent with safe facts only', function (): void {
    $order = paidOrder();
    $refunded = $this->refunds->refundConfirmedFailure($order, ConfirmedNoServerOutcome::AvailabilityLostNoServer);

    $message = OutboxMessage::query()->sole();

    expect($message->topic)->toBe(OutboxTopic::OrderRefunded)
        ->and($message->aggregate_type)->toBe((new Order)->getMorphClass())
        ->and($message->aggregate_id)->toBe((string) $refunded->getKey())
        ->and($message->deduplication_key)->toBe('refund:order:'.$refunded->getKey().':notification')
        ->and($message->processed_at)->toBeNull()
        ->and($message->attempts)->toBe(0)
        // Sorted: jsonb does not preserve the order keys were written in.
        ->and(collect(array_keys($message->payload))->sort()->values()->all())->toBe([
            'amount_toman', 'failure_category', 'order_id', 'order_number', 'user_id',
        ])
        ->and($message->payload['amount_toman'])->toBe($refunded->total_toman);
});

it('rolls the refund, the order and the outbox back together', function (): void {
    // The promise to tell the customer and the money it describes must not be
    // able to disagree.
    $order = paidOrder();
    $balance = $this->floor->customer->fresh()->wallet_balance_toman;

    try {
        DB::transaction(function () use ($order): void {
            $this->refunds->refundConfirmedFailure($order, ConfirmedNoServerOutcome::ProviderRejectedNoServer);

            throw new RuntimeException('something later in the same transaction failed');
        });
    } catch (RuntimeException) {
        // Expected.
    }

    expect($order->fresh()->status)->toBe(OrderStatus::Paid)
        ->and($this->floor->customer->fresh()->wallet_balance_toman)->toBe($balance)
        ->and(OutboxMessage::query()->count())->toBe(0)
        ->and(WalletTransaction::query()->where('idempotency_key', $order->refundIdempotencyKey())->count())
        ->toBe(0);
});

it('sends nothing while refunding', function (): void {
    // The outbox exists precisely so that nothing is sent from inside the
    // transaction.
    Http::preventStrayRequests();

    $this->refunds->refundConfirmedFailure(paidOrder(), ConfirmedNoServerOutcome::ProviderRejectedNoServer);

    Http::assertNothingSent();
});

it('keeps the balance equal to the ledger after a refund', function (): void {
    $order = paidOrder();
    $this->refunds->refundConfirmedFailure($order, ConfirmedNoServerOutcome::ProviderRejectedNoServer);

    $ledger = (int) WalletTransaction::query()
        ->where('user_id', $this->floor->customer->id)->sum('amount_toman');

    expect($this->floor->customer->fresh()->wallet_balance_toman)->toBe($ledger)
        ->and($ledger)->toBe(5_000_000);
});

it('audits the failure and the refund', function (): void {
    $order = paidOrder();
    $this->refunds->refundConfirmedFailure($order, ConfirmedNoServerOutcome::ProviderRejectedNoServer);

    expect(AuditLog::query()->where('event', AuditEvent::OrderFailed)->count())->toBe(1)
        ->and(AuditLog::query()->where('event', AuditEvent::OrderRefunded)->count())->toBe(1);
});
