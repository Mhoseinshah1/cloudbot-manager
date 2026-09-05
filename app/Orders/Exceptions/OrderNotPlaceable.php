<?php

declare(strict_types=1);

namespace App\Orders\Exceptions;

use App\Enums\OrderRefusalReason;
use RuntimeException;

/**
 * An order was not created, and this says why in a way code can branch on.
 *
 * The reason is an enum because a Telegram flow has to decide what to tell a
 * customer, and reading an exception message to do that would break the moment
 * someone rewords it. The message is for logs and for developers.
 */
final class OrderNotPlaceable extends RuntimeException
{
    private function __construct(
        public readonly OrderRefusalReason $reason,
        string $detail,
    ) {
        parent::__construct($detail);
    }

    public static function because(OrderRefusalReason $reason, string $detail): self
    {
        return new self($reason, $detail);
    }
}
