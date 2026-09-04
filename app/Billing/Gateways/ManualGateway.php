<?php

declare(strict_types=1);

namespace App\Billing\Gateways;

use App\Billing\Contracts\PaymentGatewayInterface;
use App\Billing\Data\PaymentInstruction;
use App\Billing\Data\PaymentVerificationResult;
use App\Models\Payment;
use App\Support\Secrets\SecretScrubber;

/**
 * Payments a person checks by hand.
 *
 * The customer transfers money out of band and an operator confirms the bank
 * reference. Nothing here talks to a network.
 *
 * This is NOT a production payment gateway. It exists for development, staging
 * and as an emergency route when an automated gateway is unavailable. Public,
 * automated selling needs a real gateway: every payment taken this way costs an
 * operator's attention, and a customer waits until someone is available. That
 * limitation is deliberate and must not be papered over by making this appear
 * automated.
 */
final class ManualGateway implements PaymentGatewayInterface
{
    public const CODE = 'manual';

    public function code(): string
    {
        return self::CODE;
    }

    public function name(): string
    {
        return 'Manual verification';
    }

    /**
     * Always false, and deliberately so.
     *
     * Callers use this to decide whether automated selling is possible.
     */
    public function isAutomated(): bool
    {
        return false;
    }

    public function instructionsFor(Payment $payment): PaymentInstruction
    {
        return PaymentInstruction::manual(
            'Transfer the amount shown and send the bank reference. '
            .'An operator will confirm it before the wallet is credited.'
        );
    }

    /**
     * Accept the operator's evidence, or refuse it.
     *
     * The only judgement made here is whether a usable reference was supplied.
     * Whether the operator is allowed to make this decision, and what happens
     * to the money afterwards, is settled elsewhere — this class must never be
     * the thing that authorises a credit.
     *
     * @param  array<string, scalar|null>  $evidence
     */
    public function verify(Payment $payment, array $evidence): PaymentVerificationResult
    {
        $reference = $evidence['reference'] ?? null;
        $reference = is_string($reference) ? trim($reference) : '';

        if ($reference === '') {
            // Without a reference the payment cannot be traced back to a real
            // transfer, and nothing stops the same money settling twice.
            return PaymentVerificationResult::rejected('A bank reference is required.');
        }

        if (mb_strlen($reference) > 190) {
            return PaymentVerificationResult::rejected('That reference is too long.');
        }

        $note = $evidence['note'] ?? null;

        return PaymentVerificationResult::verified($reference, [
            'note' => is_string($note) ? SecretScrubber::scrubText($note) : null,
            'verified_manually' => true,
        ]);
    }
}
