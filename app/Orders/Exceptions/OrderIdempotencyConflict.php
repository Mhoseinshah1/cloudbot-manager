<?php

declare(strict_types=1);

namespace App\Orders\Exceptions;

use RuntimeException;

/**
 * One idempotency key was used for two different purchases.
 *
 * A retry is supposed to mean "I am not sure my first request arrived". If the
 * second request describes something else — another customer, another product,
 * another image, other terms — then one of the two is not what the caller
 * thinks it is, and returning the existing order would tell them a purchase
 * succeeded that they never made.
 *
 * So this fails closed. The caller uses a new key for a new purchase.
 */
final class OrderIdempotencyConflict extends RuntimeException
{
    private function __construct(
        public readonly string $idempotencyKey,
        public readonly string $field,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function on(string $idempotencyKey, string $field): self
    {
        return new self(
            $idempotencyKey,
            $field,
            "That idempotency key already belongs to an order with a different {$field}.",
        );
    }
}
