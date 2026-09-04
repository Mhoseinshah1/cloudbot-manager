<?php

declare(strict_types=1);

namespace App\Orders\Exceptions;

use RuntimeException;

/**
 * Something tried to change what a customer was quoted.
 *
 * An order records a conversation that already happened: this price, this rate,
 * these terms, on this date. Editing it afterwards does not correct the past,
 * it destroys the only account of it. A genuinely wrong order is resolved by a
 * refund and a new order, both of which leave the mistake visible.
 */
final class OrderSnapshotIsImmutable extends RuntimeException
{
    public static function cannotChange(string $attribute): self
    {
        return new self("An order's {$attribute} is fixed at creation and cannot be changed.");
    }
}
