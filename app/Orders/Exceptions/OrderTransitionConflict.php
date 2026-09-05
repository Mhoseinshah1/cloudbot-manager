<?php

declare(strict_types=1);

namespace App\Orders\Exceptions;

use App\Enums\OrderStatus;
use RuntimeException;

/**
 * A state change did not happen, and the caller needs to know which kind.
 *
 * Two situations produce this, and they mean different things to whoever
 * catches it. `notAllowed` is a bug: the code asked for an edge the lifecycle
 * does not have. `lost` is a race: the edge was legitimate, but by the time the
 * update ran the order was somewhere else, because something else got there
 * first. The second is normal under concurrency and is exactly what the
 * compare-and-set exists to detect — the alternative would be overwriting a
 * newer state with an older decision.
 */
final class OrderTransitionConflict extends RuntimeException
{
    private function __construct(
        public readonly OrderStatus $expected,
        public readonly OrderStatus $target,
        public readonly ?OrderStatus $actual,
        string $message,
    ) {
        parent::__construct($message);
    }

    /** The lifecycle has no such edge. */
    public static function notAllowed(OrderStatus $from, OrderStatus $to): self
    {
        return new self($from, $to, $from, "An order cannot move from {$from->value} to {$to->value}.");
    }

    /**
     * The edge exists, but the order was no longer where the caller thought.
     *
     * `actual` is the state found afterwards, or null if the row has gone.
     */
    public static function lost(OrderStatus $expected, OrderStatus $target, ?OrderStatus $actual): self
    {
        $found = $actual === null ? 'nothing' : $actual->value;

        return new self(
            $expected,
            $target,
            $actual,
            "Expected the order to be {$expected->value} before moving it to {$target->value}, found {$found}.",
        );
    }

    /** Whether the order already reached the state the caller wanted. */
    public function alreadyAtTarget(): bool
    {
        return $this->actual === $this->target;
    }
}
