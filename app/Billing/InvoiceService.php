<?php

declare(strict_types=1);

namespace App\Billing;

use App\Billing\Exceptions\PaymentNotVerifiable;
use App\Enums\InvoiceStatus;
use App\Enums\InvoiceType;
use App\Models\Invoice;
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
