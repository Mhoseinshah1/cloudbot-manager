<?php

declare(strict_types=1);

namespace App\Wallet\Exceptions;

use RuntimeException;

/**
 * Someone without the authority tried to adjust a balance by hand.
 *
 * Adjustment is the one operation that can move money in either direction for
 * any reason, so it is gated on an explicit permission and a written reason.
 */
final class UnauthorizedAdjustment extends RuntimeException
{
    public static function forActor(): self
    {
        return new self('This account may not adjust wallet balances.');
    }

    public static function missingReason(): self
    {
        return new self('A wallet adjustment requires a reason.');
    }
}
