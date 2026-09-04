<?php

declare(strict_types=1);

namespace App\Orders\Exceptions;

use App\Enums\RefundRefusalReason;
use RuntimeException;

/**
 * A refund was asked for and must not happen.
 *
 * Every case here is a refusal to give money away on insufficient evidence.
 * The reason is an enum because an operator screen and a reconciliation job
 * need to tell them apart: "we never charged this customer" and "we do not yet
 * know whether a server exists" call for completely different responses, and
 * only one of them will ever become a refund.
 */
final class RefundNotPermitted extends RuntimeException
{
    private function __construct(
        public readonly RefundRefusalReason $reason,
        string $detail,
    ) {
        parent::__construct($detail);
    }

    public static function because(RefundRefusalReason $reason, string $detail): self
    {
        return new self($reason, $detail);
    }
}
