<?php

declare(strict_types=1);

namespace App\Billing\Exceptions;

use RuntimeException;

/**
 * A payment was offered to a gateway that does not own it.
 *
 * The gateway recorded on a payment is the authoritative statement of who may
 * settle it. Without that binding, a gateway that verifies by hand becomes a
 * way to settle payments belonging to gateways that verify against a remote
 * API — someone with permission to accept a bank transfer could mark an
 * automated payment paid without any money having arrived.
 *
 * Distinct from a rejected reference on purpose: this is not a payment that
 * failed verification, it is a request that should never have been made, and
 * it must not be retried by supplying better evidence.
 */
final class GatewayMismatch extends RuntimeException
{
    private function __construct(
        public readonly string $paymentGateway,
        public readonly string $attemptedGateway,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function between(string $paymentGateway, string $attemptedGateway): self
    {
        return new self(
            $paymentGateway,
            $attemptedGateway,
            "This payment belongs to the {$paymentGateway} gateway and cannot be handled by {$attemptedGateway}.",
        );
    }
}
