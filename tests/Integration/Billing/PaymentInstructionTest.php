<?php

declare(strict_types=1);

use App\Audit\AuditEvent;
use App\Authorization\RoleProvisioner;
use App\Billing\Exceptions\GatewayMismatch;
use App\Billing\Gateways\ManualGateway;
use App\Billing\PaymentService;
use App\Enums\PaymentStatus;
use App\Models\AuditLog;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\User;
use App\Models\WalletTransaction;
use Illuminate\Support\Str;
use Tests\Support\Billing\SpyingGateway;

beforeEach(function (): void {
    app(RoleProvisioner::class)->sync();

    $this->payments = app(PaymentService::class);
    $this->manual = app(ManualGateway::class);
    $this->customer = User::factory()->fromTelegram()->create();
});

/** A payment recorded against a named gateway, without going through it. */
function instructionPaymentOn(string $gateway, int $amount = 500_000): Payment
{
    return test()->payments->createPayment(
        test()->customer,
        SpyingGateway::permissive($gateway),
        $amount,
        (string) Str::uuid(),
    );
}

it('refuses instructions from a gateway that does not own the payment', function (): void {
    // Telling a customer to make a bank transfer for a payment an automated
    // gateway is waiting on sends real money down a route nothing will
    // reconcile it against.
    $payment = $this->payments->createPayment(
        $this->customer, $this->manual, 500_000, (string) Str::uuid(),
    );
    $other = new SpyingGateway;

    expect(fn () => $this->payments->instructions($payment, $other))
        ->toThrow(GatewayMismatch::class);

    expect($other->instructionCallCount())->toBe(0);
});

it('refuses manual instructions for another gateway\'s payment', function (): void {
    $payment = instructionPaymentOn(SpyingGateway::CODE);

    expect(fn () => $this->payments->instructions($payment, $this->manual))
        ->toThrow(GatewayMismatch::class);
});

it('never enters the mismatched gateway\'s instructionsFor method', function (): void {
    // The spy throws if entered, so passing proves the refusal came first
    // rather than the instructions merely being discarded afterwards.
    $payment = $this->payments->createPayment(
        $this->customer, $this->manual, 250_000, (string) Str::uuid(),
    );
    $other = new SpyingGateway;

    try {
        $this->payments->instructions($payment, $other);
        $this->fail('A mismatched gateway produced payment instructions.');
    } catch (GatewayMismatch $exception) {
        expect($exception->paymentGateway)->toBe(ManualGateway::CODE)
            ->and($exception->attemptedGateway)->toBe(SpyingGateway::CODE);
    }

    expect($other->instructionCalls)->toBe([]);
});

it('mutates nothing when instructions are refused', function (): void {
    $payment = instructionPaymentOn(SpyingGateway::CODE);

    expect(fn () => $this->payments->instructions($payment, $this->manual))
        ->toThrow(GatewayMismatch::class);

    $fresh = $payment->fresh();

    expect($fresh->gateway)->toBe(SpyingGateway::CODE)
        ->and($fresh->status)->toBe(PaymentStatus::Pending)
        ->and($fresh->paid_at)->toBeNull()
        ->and($fresh->provider_payment_id)->toBeNull()
        ->and($this->customer->fresh()->wallet_balance_toman)->toBe(0)
        ->and(WalletTransaction::query()->count())->toBe(0)
        ->and(Invoice::query()->count())->toBe(0)
        ->and(AuditLog::query()->where('event', AuditEvent::PaymentVerified)->count())->toBe(0);
});

it('returns manual instructions for a manual payment', function (): void {
    $payment = $this->payments->createPayment(
        $this->customer, $this->manual, 300_000, (string) Str::uuid(),
    );

    $instruction = $this->payments->instructions($payment, $this->manual);

    expect($instruction->requiresManualVerification)->toBeTrue()
        ->and($instruction->redirectUrl)->toBeNull()
        ->and($instruction->message)->toContain('bank reference');
});

it('returns the other gateway\'s own instructions for its own payment', function (): void {
    $payment = instructionPaymentOn(SpyingGateway::CODE);
    $gateway = SpyingGateway::permissive();

    $instruction = $this->payments->instructions($payment, $gateway);

    expect($instruction->requiresManualVerification)->toBeFalse()
        ->and($instruction->redirectUrl)->toBe('https://example.invalid/pay')
        ->and($gateway->instructionCallCount())->toBe(1);
});

it('refuses a mismatched payment inside ManualGateway itself', function (): void {
    // Defence in depth. PaymentService is the guarantee; this makes the one
    // gateway that would ask for a real hand-made transfer refuse even when
    // reached directly.
    $payment = instructionPaymentOn(SpyingGateway::CODE);

    expect(fn () => $this->manual->instructionsFor($payment))
        ->toThrow(GatewayMismatch::class);
});

it('still answers when ManualGateway is called directly for its own payment', function (): void {
    $payment = $this->payments->createPayment(
        $this->customer, $this->manual, 120_000, (string) Str::uuid(),
    );

    expect($this->manual->instructionsFor($payment)->requiresManualVerification)->toBeTrue();
});
