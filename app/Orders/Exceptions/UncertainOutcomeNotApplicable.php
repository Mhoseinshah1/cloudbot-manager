<?php

declare(strict_types=1);

namespace App\Orders\Exceptions;

use App\Enums\OrderStatus;
use RuntimeException;

/**
 * An uncertain remote result was reported for an order that has no remote
 * request in flight.
 *
 * "We do not know whether the provider created a server" is only a meaningful
 * statement about an order that actually asked one to. A `paid` order has not
 * reached the provider yet, so recording uncertainty against it would invent a
 * doubt that cannot exist — and park a perfectly refundable order in
 * needs_attention, where nothing automatic will ever resolve it.
 */
final class UncertainOutcomeNotApplicable extends RuntimeException
{
    public static function from(OrderStatus $status): self
    {
        return new self(
            "An order in {$status->value} has no provider request in flight, so its outcome cannot be uncertain.",
        );
    }
}
