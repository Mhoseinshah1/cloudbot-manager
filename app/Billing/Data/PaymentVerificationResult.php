<?php

declare(strict_types=1);

namespace App\Billing\Data;

use App\Support\Secrets\SecretScrubber;

/**
 * A gateway's answer to "did this payment actually happen?".
 *
 * Normalized, so settlement never reads a gateway's own response shape. The
 * reference is what makes the answer checkable afterwards: for a manual payment
 * it is the bank reference, and it is what stops the same real-world transfer
 * being used to settle two payments.
 */
final readonly class PaymentVerificationResult
{
    /**
     * @param  array<string, scalar|null>  $metadata
     */
    private function __construct(
        public bool $verified,
        public ?string $reference,
        public string $message,
        public array $metadata,
    ) {}

    /**
     * @param  array<string, scalar|null>  $metadata
     */
    public static function verified(string $reference, array $metadata = []): self
    {
        return new self(true, $reference, 'Payment verified.', SecretScrubber::scrub($metadata));
    }

    /**
     * @param  array<string, scalar|null>  $metadata
     */
    public static function rejected(string $message, array $metadata = []): self
    {
        // No reference: nothing was accepted, so there is nothing to correlate.
        return new self(false, null, SecretScrubber::scrubText($message), SecretScrubber::scrub($metadata));
    }
}
