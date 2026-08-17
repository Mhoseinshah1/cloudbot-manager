<?php

namespace App\Providers\Payment;

use App\Contracts\PaymentGatewayInterface;
use App\Models\Payment;

/**
 * Manual gateway for development and testing. An operator marks the
 * payment as approved/rejected; no external calls are made.
 */
class ManualGateway implements PaymentGatewayInterface
{
    public function code(): string
    {
        return 'manual';
    }

    public function requestPayment(Payment $payment): array
    {
        return [
            'status' => 'pending',
            'reference' => 'manual-'.$payment->payment_uuid,
        ];
    }

    public function verifyPayment(Payment $payment, array $data = []): bool
    {
        return (bool) ($data['approved'] ?? true);
    }

    public function refund(Payment $payment, ?int $amountToman = null): bool
    {
        return true;
    }
}
