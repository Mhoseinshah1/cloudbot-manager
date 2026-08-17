<?php

use App\Models\Payment;
use App\Providers\Payment\ManualGateway;

function manualPayment(): Payment
{
    return new Payment([
        'payment_uuid' => '8f5b94b9-7f1c-4c1a-9f2a-1234567890ab',
        'amount_toman' => 100000,
    ]);
}

it('requests a payment without external calls', function () {
    $result = (new ManualGateway)->requestPayment(manualPayment());

    expect($result['status'])->toBe('pending');
    expect($result['reference'])->toContain('8f5b94b9');
});

it('verifies approved payments and rejects rejected ones', function () {
    $gateway = new ManualGateway;
    $payment = manualPayment();

    expect($gateway->verifyPayment($payment, ['approved' => true]))->toBeTrue();
    expect($gateway->verifyPayment($payment, ['approved' => false]))->toBeFalse();
    expect($gateway->verifyPayment($payment))->toBeTrue(); // defaults to approved
});

it('refunds without external calls', function () {
    expect((new ManualGateway)->refund(manualPayment()))->toBeTrue();
});
