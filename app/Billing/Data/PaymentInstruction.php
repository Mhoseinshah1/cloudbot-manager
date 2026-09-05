<?php

declare(strict_types=1);

namespace App\Billing\Data;

/**
 * What a customer must do to complete a payment.
 *
 * An automated gateway returns a URL to send them to. A manual one returns
 * instructions for a transfer someone will check by hand. Both are described
 * here so the code that starts a payment does not need to know which it has.
 */
final readonly class PaymentInstruction
{
    private function __construct(
        public bool $requiresManualVerification,
        public ?string $redirectUrl,
        public string $message,
    ) {}

    public static function redirect(string $url, string $message = ''): self
    {
        return new self(false, $url, $message);
    }

    /**
     * The customer pays out of band and someone verifies it afterwards.
     */
    public static function manual(string $message): self
    {
        return new self(true, null, $message);
    }
}
