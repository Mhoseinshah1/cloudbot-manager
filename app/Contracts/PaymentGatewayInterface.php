<?php

namespace App\Contracts;

use App\Models\Payment;

interface PaymentGatewayInterface
{
    /**
     * Machine-readable gateway code (manual, zarinpal, ...).
     */
    public function code(): string;

    /**
     * Start a payment. Returns gateway data, e.g. an approval URL.
     *
     * @return array<string, mixed>
     */
    public function requestPayment(Payment $payment): array;

    /**
     * Verify a payment after the customer returns from the gateway or a
     * webhook/callback arrives. Implementations must be idempotent.
     *
     * @param  array<string, mixed>  $data
     */
    public function verifyPayment(Payment $payment, array $data = []): bool;

    /**
     * Refund a payment (full or partial).
     */
    public function refund(Payment $payment, ?int $amountToman = null): bool;
}
