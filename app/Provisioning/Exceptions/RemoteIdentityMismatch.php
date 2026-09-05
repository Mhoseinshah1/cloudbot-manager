<?php

declare(strict_types=1);

namespace App\Provisioning\Exceptions;

use App\Models\Order;
use RuntimeException;

/**
 * A provider returned a server that is not the one this order asked for.
 *
 * Every field is checked because none of them can be assumed. A lookup that
 * matched loosely, a provider answering for a different request, or a token
 * echoed back wrong all produce a plausible-looking server object; persisting it
 * would record somebody else's machine — or a machine of the wrong size in the
 * wrong country — as this customer's purchase.
 *
 * The correct response is never to accept it and never to create another. The
 * order goes to needs_attention with the money untouched, and a person decides.
 */
final class RemoteIdentityMismatch extends RuntimeException
{
    private function __construct(
        public readonly int $orderId,
        public readonly string $field,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function on(Order $order, string $field, ?string $expected, ?string $actual): self
    {
        return new self(
            (int) $order->getKey(),
            $field,
            "The server returned for order {$order->order_number} has the wrong {$field}: "
            .'expected '.self::show($expected).', got '.self::show($actual).'.',
        );
    }

    private static function show(?string $value): string
    {
        return $value === null || $value === '' ? '(none)' : $value;
    }
}
