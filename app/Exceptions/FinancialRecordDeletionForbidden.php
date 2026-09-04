<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Raised when something tries to erase a financial record.
 *
 * Payments and invoices are not immutable — a payment moves from pending to
 * paid, an invoice's status can legitimately change — but they are retained.
 * Deleting one destroys the answer to "what was this customer charged, and
 * why", which is the question the record exists to answer, and which is asked
 * long after the row stops being operationally interesting.
 *
 * A record that turns out to be wrong is corrected by a further record — a
 * refund, a cancellation — that says so, leaving the history of the mistake
 * intact. That is a different thing from removing the evidence.
 */
final class FinancialRecordDeletionForbidden extends RuntimeException
{
    private function __construct(public readonly string $record, string $message)
    {
        parent::__construct($message);
    }

    public static function forPayment(): self
    {
        return new self('payment', 'Payments are retained financial history and cannot be deleted.');
    }

    public static function forInvoice(): self
    {
        return new self('invoice', 'Invoices are retained financial history and cannot be deleted.');
    }

    public static function forOrder(): self
    {
        return new self('order', 'Orders are retained financial history and cannot be deleted.');
    }
}
