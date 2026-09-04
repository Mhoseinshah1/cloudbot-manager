<?php

declare(strict_types=1);

namespace App\Orders;

use App\Audit\AuditEvent;
use App\Audit\AuditRecorder;
use App\Enums\ConfirmedNoServerOutcome;
use App\Enums\OrderFailureCategory;
use App\Enums\OrderStatus;
use App\Enums\RefundRefusalReason;
use App\Enums\WalletTransactionType;
use App\Models\Order;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Orders\Exceptions\RefundNotPermitted;
use App\Outbox\OutboxTopic;
use App\Outbox\OutboxWriter;
use App\Support\Secrets\SecretScrubber;
use App\Wallet\WalletService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

/**
 * Gives a customer's money back when their order will never become a server.
 *
 * The method takes a ConfirmedNoServerOutcome and nothing else. That enum has
 * no case meaning "probably" or "we timed out", so a caller holding only a
 * suspicion cannot express it here — they would have to add a case, which is a
 * visible edit in a review rather than a boolean quietly passed as true. An
 * uncertain create is the case this design exists for: a request that timed out
 * after the provider received it looks identical to one that failed, and
 * refunding it hands back the money for a server the customer still has.
 *
 * Evidence, not status. Before crediting anything it looks for the actual debit
 * in the immutable ledger — this customer, this order, exactly this amount. A
 * status column is something code writes; the ledger is the record of money
 * having moved, and only the second one can justify moving it back.
 *
 * Everything happens in one transaction: the failure, the credit, the refunded
 * state, the audit, and the promise to tell the customer. A crash cannot leave
 * a refunded order with no money in it, or money returned with nobody told.
 */
final readonly class RefundService
{
    public function __construct(
        private WalletService $wallet,
        private OrderStateMachine $states,
        private OutboxWriter $outbox,
        private AuditRecorder $audit,
    ) {}

    /**
     * Fail an order and return the full amount to the customer's wallet.
     *
     * Safe to call again. A replay finds the refund that already happened and
     * returns the same order rather than crediting a second time — the wallet
     * key, the order's state and the outbox key each independently prevent it.
     *
     * @param  ConfirmedNoServerOutcome  $outcome  What is *known* to have happened.
     * @param  string|null  $reason  Free text for an operator. Scrubbed before storage.
     */
    public function refundConfirmedFailure(
        Order $order,
        ConfirmedNoServerOutcome $outcome,
        ?string $reason = null,
    ): Order {
        return DB::transaction(function () use ($order, $outcome, $reason): Order {
            // Customer first, then order — the same order WalletService takes,
            // so a refund and a concurrent wallet movement queue rather than
            // deadlock.
            $customer = User::query()->whereKey($order->user_id)->lockForUpdate()->first();
            $locked = Order::query()->whereKey($order->getKey())->lockForUpdate()->first();

            if (! $customer instanceof User || ! $locked instanceof Order) {
                throw new ModelNotFoundException('The order or its owner no longer exists.');
            }

            if ($locked->status === OrderStatus::Refunded) {
                // Already done, by an earlier call or a concurrent one.
                return $locked;
            }

            $this->assertRefundable($locked);
            $charge = $this->requireCommittedCharge($locked);

            $failed = $this->markFailed($locked, $outcome, $reason);

            // Keyed exactly as the specification requires. This key is what
            // makes the refund happen once even when the decision is reached
            // twice.
            $this->wallet->refund(
                $customer,
                $failed->total_toman,
                $failed->refundIdempotencyKey(),
                'Refund for order '.$failed->order_number,
                $failed,
                ['order_id' => $failed->getKey(), 'wallet_transaction_id' => $charge->getKey()],
            );

            $refunded = $this->states->transition($failed, OrderStatus::Failed, OrderStatus::Refunded);

            $this->audit->record(
                AuditEvent::OrderRefunded,
                subject: $refunded,
                before: ['status' => OrderStatus::Failed->value],
                after: ['status' => $refunded->status->value],
                metadata: [
                    'order_id' => $refunded->getKey(),
                    'order_number' => $refunded->order_number,
                    'user_id' => $refunded->user_id,
                    'amount_toman' => $refunded->total_toman,
                    'failure_category' => $refunded->failure_category?->value,
                    'idempotency_key' => $refunded->refundIdempotencyKey(),
                ],
            );

            // Inside the transaction, deliberately. A customer told their money
            // is back before the commit that puts it back would have been told
            // something untrue by a system that cannot take it back.
            $this->outbox->record(
                OutboxTopic::OrderRefunded,
                $refunded,
                [
                    'order_id' => $refunded->getKey(),
                    'order_number' => $refunded->order_number,
                    'user_id' => $refunded->user_id,
                    'amount_toman' => $refunded->total_toman,
                    'failure_category' => $refunded->failure_category?->value,
                ],
                $refunded->refundIdempotencyKey().':notification',
            );

            return $refunded;
        });
    }

    /**
     * Record a failure without giving any money back.
     *
     * For outcomes that are not confirmed, and for orders that were never paid.
     * Separate from the refund path on purpose: an uncertain result has to be
     * recordable, and if recording it shared a method with refunding it, the
     * difference would come down to an argument somebody could get wrong.
     */
    public function markFailedWithoutRefund(
        Order $order,
        OrderFailureCategory $category,
        ?string $reason = null,
    ): Order {
        return DB::transaction(function () use ($order, $category, $reason): Order {
            $locked = Order::query()->whereKey($order->getKey())->lockForUpdate()->first();

            if (! $locked instanceof Order) {
                throw new ModelNotFoundException('That order no longer exists.');
            }

            return $this->writeFailure($locked, $category, $reason);
        });
    }

    /**
     * The order must be somewhere a refund can follow from.
     */
    private function assertRefundable(Order $order): void
    {
        if (! $order->status->isFunded()) {
            // Provisioned, expired, cancelled, or never paid at all. None of
            // these is a failed purchase owed money back.
            throw RefundNotPermitted::because(
                RefundRefusalReason::OrderNotRefundable,
                "An order in {$order->status->value} cannot be failure-refunded.",
            );
        }

        if ($order->status === OrderStatus::Provisioned) {
            throw RefundNotPermitted::because(
                RefundRefusalReason::OrderNotRefundable,
                'That order was provisioned; the customer has their server.',
            );
        }
    }

    /**
     * Find the debit that actually took this customer's money.
     *
     * The ledger is immutable and each row records what moved, for whom, and
     * against what. Requiring a matching debit here is what stops a refund
     * inventing money: an order whose status says paid but whose customer was
     * never charged produces no row, and no refund.
     */
    private function requireCommittedCharge(Order $order): WalletTransaction
    {
        $charge = WalletTransaction::query()
            ->where('user_id', $order->user_id)
            ->where('idempotency_key', $order->paymentIdempotencyKey())
            ->where('type', WalletTransactionType::Debit->value)
            ->where('amount_toman', -$order->total_toman)
            ->first();

        if (! $charge instanceof WalletTransaction) {
            throw RefundNotPermitted::because(
                RefundRefusalReason::NoCommittedCharge,
                'No committed wallet charge exists for that order.',
            );
        }

        return $charge;
    }

    /**
     * Move the order to failed, unless it is already there.
     */
    private function markFailed(Order $order, ConfirmedNoServerOutcome $outcome, ?string $reason): Order
    {
        if ($order->status === OrderStatus::Failed) {
            // Already failed by an earlier attempt; the refund still has to
            // happen, so this is a resumption rather than a conflict.
            return $order;
        }

        return $this->writeFailure($order, $outcome->category(), $reason);
    }

    /**
     * Write the failure state and its reason together.
     *
     * One statement, so a failed order can never be missing the explanation for
     * why it failed.
     */
    private function writeFailure(
        Order $order,
        OrderFailureCategory $category,
        ?string $reason,
    ): Order {
        $failed = $this->states->transition($order, $order->status, OrderStatus::Failed, [
            'failure_category' => $category->value,
            // Scrubbed. A provider error message is the most likely place for a
            // token or an Authorization header to arrive, and this column is
            // read by support staff and shown in operator screens.
            'failure_reason' => $reason === null ? null : SecretScrubber::scrubText($reason),
        ]);

        $this->audit->record(
            AuditEvent::OrderFailed,
            subject: $failed,
            after: ['status' => $failed->status->value],
            metadata: [
                'order_id' => $failed->getKey(),
                'order_number' => $failed->order_number,
                'failure_category' => $category->value,
            ],
        );

        return $failed;
    }
}
