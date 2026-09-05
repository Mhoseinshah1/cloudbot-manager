<?php

declare(strict_types=1);

namespace App\Wallet\Exceptions;

use RuntimeException;

/**
 * The wallet does not hold enough to cover this movement.
 *
 * A stable domain error, not a message to parse. There is no overdraft: a
 * balance that cannot cover a debit means the debit does not happen, not that
 * the balance goes negative.
 */
final class InsufficientBalance extends RuntimeException
{
    private function __construct(
        public readonly int $balanceToman,
        public readonly int $requestedToman,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function forDebit(int $balanceToman, int $requestedToman): self
    {
        return new self(
            $balanceToman,
            $requestedToman,
            'The wallet balance is too low for this debit.',
        );
    }

    public static function forAdjustment(int $balanceToman, int $requestedToman): self
    {
        return new self(
            $balanceToman,
            $requestedToman,
            'That adjustment would take the wallet balance below zero.',
        );
    }
}
