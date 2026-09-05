<?php

declare(strict_types=1);

namespace App\Billing\Exceptions;

use RuntimeException;

/**
 * One payment idempotency key was reused for a different intention.
 *
 * Fails closed. Returning the earlier payment would tell the caller their
 * request succeeded when a different amount, or a different customer's money,
 * is what actually exists.
 */
final class PaymentIdempotencyConflict extends RuntimeException
{
    public static function on(string $idempotencyKey, string $field): self
    {
        return new self(
            "This payment idempotency key was already used for a different request (differing {$field}).",
        );
    }
}
