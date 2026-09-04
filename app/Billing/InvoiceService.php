<?php

declare(strict_types=1);

namespace App\Billing;

use App\Billing\Exceptions\PaymentNotVerifiable;
use App\Enums\InvoiceStatus;
use App\Enums\InvoiceType;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Payment;
use Illuminate\Database\QueryException;

/**
 * Issues invoices.
 *
 * One invoice per settled payment, guaranteed by deriving the invoice number
 * from the payment's own id rather than from a counter. A counter would need
 * its own locking to be safe, and would still leave a replayed settlement able
 * to draw a second number.
 *
 * Only a settled payment may be invoiced. Settlement calls this after marking
 * the payment paid, but nothing stops another caller reaching it directly, and
 * an invoice for an unsettled payment would record funding that never happened.
 * The same rule applies to an order: it must have been paid for.
 */
final class InvoiceService
{
    /**
     * Issue the invoice for a settled payment, or return the one that exists.
     *
     * Safe to call again: a replayed settlement finds the same invoice instead
     * of issuing a second one for the same money.
     */
    public function issueForPayment(Payment $payment): Invoice
    {
        if (! $payment->status->isSettled()) {
            // An invoice says a customer was charged and their wallet funded.
            // Issuing one for a payment that never settled would document money
            // that does not exist, and this service can be called from outside
            // the settlement path.
            throw PaymentNotVerifiable::notOpen($payment->status->value);
        }

        $number = $this->numberForPayment($payment);

        $existing = Invoice::query()->where('number', $number)->first();

        if ($existing instanceof Invoice) {
            return $existing;
        }

        try {
            return Invoice::query()->create([
                'user_id' => $payment->user_id,
                'number' => $number,
                'type' => InvoiceType::WalletTopUp,
                'amount_toman' => $payment->amount_toman,
                'status' => InvoiceStatus::Issued,
                'issued_at' => now(),
                'line_items' => [[
                    'description' => 'Wallet top-up',
                    'quantity' => 1,
                    'unit_price_toman' => $payment->amount_toman,
                    'total_toman' => $payment->amount_toman,
                ]],
                // Empty on purpose. This is Toman paid into a Toman wallet, so
                // no rate was applied; inventing one would be a fabricated
                // record of a conversion that never happened.
                'pricing_snapshot' => null,
            ]);
        } catch (QueryException $exception) {
            // A concurrent settlement issued it first. Its invoice is the right
            // answer, not an error and not a second document.
            $winner = Invoice::query()->where('number', $number)->first();

            if ($winner instanceof Invoice) {
                return $winner;
            }

            throw $exception;
        }
    }

    /**
     * Issue the invoice for a paid order, or return the one that exists.
     *
     * A separate document from a wallet top-up invoice and a separate number
     * series, because they record different things: one says money came in,
     * this one says money was spent on a specific server. Numbering it from the
     * order's own id makes issuing a second one for the same purchase
     * impossible without a counter that would need its own locking.
     *
     * The prices come from the order's frozen snapshot, never from the catalog.
     * An invoice recomputed at issue time would quietly disagree with what the
     * customer was charged if anything had moved in between.
     */
    public function issueForOrder(Order $order): Invoice
    {
        if (! $order->status->isFunded()) {
            // An invoice asserts the customer paid. Issuing one for an unpaid
            // order would document money that never moved, and this service is
            // reachable from outside the payment path.
            throw PaymentNotVerifiable::notOpen($order->status->value);
        }

        $number = $this->numberForOrder($order);

        $existing = Invoice::query()->where('number', $number)->first();

        if ($existing instanceof Invoice) {
            return $existing;
        }

        try {
            return Invoice::query()->create([
                'user_id' => $order->user_id,
                'order_id' => $order->getKey(),
                'number' => $number,
                'type' => InvoiceType::ServerPurchase,
                'amount_toman' => $order->total_toman,
                'status' => InvoiceStatus::Issued,
                'issued_at' => now(),
                'line_items' => [[
                    'description' => 'Server purchase, order '.$order->order_number,
                    'quantity' => 1,
                    'unit_price_toman' => $order->total_toman,
                    'total_toman' => $order->total_toman,
                ]],
                // The order's own record of what was quoted, carried across so
                // the invoice and the order can never tell different stories.
                'pricing_snapshot' => [
                    'order_number' => $order->order_number,
                    'pricing' => $order->pricing_snapshot,
                    'cost' => $order->cost_snapshot,
                ],
            ]);
        } catch (QueryException $exception) {
            // A concurrent payment issued it first.
            $winner = Invoice::query()->where('number', $number)->first();

            if ($winner instanceof Invoice) {
                return $winner;
            }

            throw $exception;
        }
    }

    /**
     * The invoice number for an order.
     *
     * A separate prefix from payment invoices, so the two series cannot collide
     * and a number says at a glance what kind of document it is.
     */
    public function numberForOrder(Order $order): string
    {
        return sprintf('INV-O%06d', (int) $order->getKey());
    }

    /**
     * The invoice number for a payment.
     *
     * Deterministic, so it can be recomputed rather than looked up, and unique
     * because payment ids are.
     */
    public function numberForPayment(Payment $payment): string
    {
        return sprintf('INV-P%06d', (int) $payment->getKey());
    }
}
