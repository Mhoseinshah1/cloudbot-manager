<?php

declare(strict_types=1);

namespace App\Billing\Exceptions;

use RuntimeException;

/**
 * Someone without the authority tried to accept a payment.
 *
 * Verifying a manual payment creates money in a customer's wallet from nothing
 * but a person's say-so, so it is restricted to accounts trusted with finance.
 */
final class UnauthorizedVerification extends RuntimeException
{
    public static function forActor(): self
    {
        return new self('This account may not verify payments.');
    }
}
