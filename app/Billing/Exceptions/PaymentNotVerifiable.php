<?php

declare(strict_types=1);

namespace App\Billing\Exceptions;

use RuntimeException;

/**
 * The payment cannot be settled in its current state.
 */
final class PaymentNotVerifiable extends RuntimeException
{
    public static function notOpen(string $status): self
    {
        return new self("A payment in the {$status} state cannot be verified.");
    }

    public static function rejected(string $reason): self
    {
        return new self($reason);
    }

    /**
     * The bank reference has already settled a different payment.
     */
    public static function referenceAlreadyUsed(): self
    {
        return new self('That reference has already been used to settle another payment.');
    }
}
