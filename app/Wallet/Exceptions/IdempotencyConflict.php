<?php

declare(strict_types=1);

namespace App\Wallet\Exceptions;

use RuntimeException;

/**
 * One idempotency key was reused for a different operation.
 *
 * This fails closed rather than returning the earlier result. Returning it
 * would tell the caller their request succeeded when something else entirely
 * happened — the caller would believe one customer was credited when another
 * was, or that 500,000 Toman moved when 50,000 did.
 */
final class IdempotencyConflict extends RuntimeException
{
    private function __construct(
        public readonly string $idempotencyKey,
        public readonly string $conflictingField,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function on(string $idempotencyKey, string $field): self
    {
        return new self(
            $idempotencyKey,
            $field,
            "This idempotency key was already used for a different operation (differing {$field}).",
        );
    }
}
