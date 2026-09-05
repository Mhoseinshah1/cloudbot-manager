<?php

declare(strict_types=1);

namespace App\Provisioning\Exceptions;

use App\Models\Order;
use RuntimeException;

/**
 * An order's frozen snapshots cannot say what to build.
 *
 * Provisioning refuses rather than filling the gap from today's catalog. The
 * snapshot is the record of what a customer bought; substituting a current
 * value would deliver something they did not pay for, and would do it silently.
 *
 * In practice this means a snapshot written by an older or broken code path,
 * which is a fault worth stopping on and looking at.
 */
final class OrderSnapshotIncomplete extends RuntimeException
{
    private function __construct(
        public readonly int $orderId,
        public readonly string $field,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function missing(Order $order, string $field): self
    {
        return new self(
            (int) $order->getKey(),
            $field,
            "Order {$order->order_number} has no usable {$field} in its snapshots, so it cannot be built.",
        );
    }
}
