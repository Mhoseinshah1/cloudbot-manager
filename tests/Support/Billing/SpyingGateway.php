<?php

declare(strict_types=1);

namespace Tests\Support\Billing;

use App\Billing\Contracts\PaymentGatewayInterface;
use App\Billing\Data\PaymentInstruction;
use App\Billing\Data\PaymentVerificationResult;
use App\Models\Payment;
use RuntimeException;

/**
 * A gateway that exists only to be watched.
 *
 * It records every call to verify() and, unless told otherwise, throws when one
 * arrives. That is what lets a test assert something stronger than "settlement
 * refused a mismatched payment": it proves the refusal happened *before* the
 * gateway was consulted at all. A gateway that merely returned a rejection
 * could not tell those two outcomes apart, and the difference matters — a real
 * automated gateway verifies by calling a remote API, so reaching its verify()
 * with someone else's payment is already a request that should never have left
 * the building.
 */
final class SpyingGateway implements PaymentGatewayInterface
{
    public const CODE = 'test-other';

    /** @var list<array{payment_id: int|string|null, evidence: array<string, scalar|null>}> */
    public array $verifyCalls = [];

    public function __construct(
        private readonly string $code = self::CODE,
        private readonly bool $throwIfEntered = true,
    ) {}

    /** A gateway whose verify() answers instead of exploding, so the happy path can be tested too. */
    public static function permissive(string $code = self::CODE): self
    {
        return new self($code, throwIfEntered: false);
    }

    public function code(): string
    {
        return $this->code;
    }

    public function name(): string
    {
        return 'Test gateway';
    }

    public function isAutomated(): bool
    {
        return true;
    }

    public function instructionsFor(Payment $payment): PaymentInstruction
    {
        return PaymentInstruction::redirect('https://example.invalid/pay');
    }

    public function verifyCallCount(): int
    {
        return count($this->verifyCalls);
    }

    /**
     * @param  array<string, scalar|null>  $evidence
     */
    public function verify(Payment $payment, array $evidence): PaymentVerificationResult
    {
        $this->verifyCalls[] = ['payment_id' => $payment->getKey(), 'evidence' => $evidence];

        if ($this->throwIfEntered) {
            // Loud on purpose. If a test that expects a mismatch to be refused
            // early sees this instead, the ordering guarantee has been lost.
            throw new RuntimeException('SpyingGateway::verify() was entered.');
        }

        $reference = $evidence['reference'] ?? null;
        $reference = is_string($reference) ? trim($reference) : '';

        if ($reference === '') {
            return PaymentVerificationResult::rejected('A reference is required.');
        }

        return PaymentVerificationResult::verified($reference, ['gateway' => $this->code]);
    }
}
