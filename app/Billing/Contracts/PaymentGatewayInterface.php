<?php

declare(strict_types=1);

namespace App\Billing\Contracts;

use App\Billing\Data\PaymentInstruction;
use App\Billing\Data\PaymentVerificationResult;
use App\Models\Payment;

/**
 * What every way of taking money must be able to do.
 *
 * Small on purpose: start a payment, and answer whether one completed. Anything
 * gateway-specific — an HTTP client, an SDK model, a signature scheme — stays
 * inside the implementation. Settlement works from the normalized results
 * above, so adding a gateway never changes how money reaches a wallet.
 */
interface PaymentGatewayInterface
{
    /** Stable identifier stored on every payment this gateway handles. */
    public function code(): string;

    public function name(): string;

    /**
     * Whether this gateway confirms payments without a person.
     *
     * False means every payment it takes needs an operator to check it, which
     * is a constraint on how the business can run, not merely a detail.
     */
    public function isAutomated(): bool;

    /**
     * Begin a payment and tell the customer what to do next.
     */
    public function instructionsFor(Payment $payment): PaymentInstruction;

    /**
     * Decide whether a payment completed.
     *
     * @param  array<string, scalar|null>  $evidence  What the caller offers as proof:
     *                                                a bank reference for a manual payment, a callback payload for an
     *                                                automated one.
     */
    public function verify(Payment $payment, array $evidence): PaymentVerificationResult;
}
