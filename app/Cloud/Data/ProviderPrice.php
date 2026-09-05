<?php

declare(strict_types=1);

namespace App\Cloud\Data;

use InvalidArgumentException;

/**
 * A price in the provider's own currency.
 *
 * The amount is a decimal string, never a float. Provider prices are converted
 * and marked up on their way to a customer, and a binary float that cannot
 * represent 0.01 exactly has no business at the start of that chain.
 *
 * This is provider cost only. It is not a customer-facing price, which is whole
 * Toman held in a BIGINT and arrives with the pricing work in a later phase.
 */
final readonly class ProviderPrice
{
    private function __construct(
        public string $amount,
        public string $currency,
    ) {}

    public static function of(string $amount, string $currency): self
    {
        if (! preg_match('/^-?\d+(\.\d+)?$/', $amount)) {
            throw new InvalidArgumentException('A provider price must be a decimal string.');
        }

        if (! preg_match('/^[A-Z]{3}$/', $currency)) {
            throw new InvalidArgumentException('A provider currency must be a three-letter code.');
        }

        return new self($amount, $currency);
    }

    public function isZero(): bool
    {
        return (float) $this->amount === 0.0;
    }

    public function __toString(): string
    {
        return $this->amount.' '.$this->currency;
    }
}
