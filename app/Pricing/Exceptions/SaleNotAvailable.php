<?php

declare(strict_types=1);

namespace App\Pricing\Exceptions;

use App\Enums\SaleRefusalReason;
use RuntimeException;

/**
 * A new sale was refused.
 *
 * Carries the reason as an enum, not as prose. Whatever displays this to a
 * customer or an operator decides what to say from `$reason`; the message is
 * for a log and for a developer reading a stack trace, and will be reworded
 * without warning. Nothing may branch on its text.
 *
 * Every refusal is a fail-closed one. There is no case here that means "sell
 * anyway" — a question this class could not answer is a question that has to
 * stop the sale.
 */
final class SaleNotAvailable extends RuntimeException
{
    private function __construct(
        public readonly SaleRefusalReason $reason,
        /** Which specific thing was wrong. For operators, never for branching. */
        public readonly string $detail,
    ) {
        parent::__construct($detail);
    }

    public static function because(SaleRefusalReason $reason, string $detail): self
    {
        return new self($reason, $detail);
    }
}
