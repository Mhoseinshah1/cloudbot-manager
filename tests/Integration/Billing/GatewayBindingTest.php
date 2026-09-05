<?php

declare(strict_types=1);

use App\Audit\AuditEvent;
use App\Authorization\RoleProvisioner;
use App\Billing\Exceptions\GatewayMismatch;
use App\Billing\Exceptions\PaymentNotVerifiable;
use App\Billing\Gateways\ManualGateway;
use App\Billing\InvoiceService;
use App\Billing\PaymentService;
use App\Enums\AdminRole;
use App\Enums\PaymentStatus;
use App\Models\AuditLog;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\User;
use App\Models\WalletTransaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\Billing\SpyingGateway;

beforeEach(function (): void {
    app(RoleProvisioner::class)->sync();

    $this->payments = app(PaymentService::class);
    $this->manual = app(ManualGateway::class);
    $this->customer = User::factory()->fromTelegram()->create();

    $this->finance = User::factory()->create();
    $this->finance->assignRole(AdminRole::Finance->value);
});

/** A payment recorded against a named gateway, without going through it. */
function paymentOn(string $gateway, int $amount = 500_000): Payment
{
    return test()->payments->createPayment(
        test()->customer,
        SpyingGateway::permissive($gateway),
        $amount,
        (string) Str::uuid(),
    );
}

/** Nothing was credited, invoiced or audited. */
function expectNothingSettled(Payment $payment): void
{
    $fresh = $payment->fresh();

    expect($fresh->status)->toBe(PaymentStatus::Pending)
        ->and($fresh->paid_at)->toBeNull()
        ->and($fresh->verified_by_admin_id)->toBeNull()
        ->and($fresh->provider_payment_id)->toBeNull()
        ->and($fresh->verification_metadata)->toBeNull()
        ->and(test()->customer->fresh()->wallet_balance_toman)->toBe(0)
        ->and(WalletTransaction::query()->count())->toBe(0)
        ->and(Invoice::query()->count())->toBe(0)
        ->and(AuditLog::query()->where('event', AuditEvent::PaymentVerified)->count())->toBe(0);
}

it('refuses to let another gateway verify a manual payment', function (): void {
    // The abuse this closes: the manual gateway accepts whatever reference a
    // person types, so anyone able to point it at an automated gateway's
    // payment could mark that payment paid without money having arrived. The
    // mirror image is checked below; both directions have to be shut.
    $payment = $this->payments->createPayment(
        $this->customer, $this->manual, 500_000, (string) Str::uuid(),
    );
    $other = new SpyingGateway;

    expect(fn () => $this->payments->verify($payment, $other, $this->finance, ['reference' => 'BANK-STOLEN']))
        ->toThrow(GatewayMismatch::class);

    expect($other->verifyCallCount())->toBe(0);
    expectNothingSettled($payment);
});

it('refuses to let the manual gateway verify another gateway\'s payment', function (): void {
    // The direction that matters most in practice. ManualGateway is the
    // staging and emergency route; it must never become a way around a gateway
    // that verifies against a real bank.
    $payment = paymentOn(SpyingGateway::CODE);

    expect(fn () => $this->payments->verify($payment, $this->manual, $this->finance, ['reference' => 'BANK-BYPASS']))
        ->toThrow(GatewayMismatch::class);

    expectNothingSettled($payment);
});

it('never enters the mismatched gateway\'s verify method', function (): void {
    // Stronger than "the settlement was refused". A real automated gateway
    // verifies by calling a remote API, so reaching its verify() with someone
    // else's payment is already a request that should never have been made.
    // The spy throws if entered, so passing proves the refusal came first.
    $payment = $this->payments->createPayment(
        $this->customer, $this->manual, 250_000, (string) Str::uuid(),
    );
    $other = new SpyingGateway;

    try {
        $this->payments->verify($payment, $other, $this->finance, ['reference' => 'BANK-EARLY']);
        $this->fail('A mismatched gateway settled a payment.');
    } catch (GatewayMismatch $exception) {
        expect($exception->paymentGateway)->toBe(ManualGateway::CODE)
            ->and($exception->attemptedGateway)->toBe(SpyingGateway::CODE);
    }

    expect($other->verifyCalls)->toBe([]);
});

it('treats a mismatch as a different kind of failure than a rejected reference', function (): void {
    // A rejected reference invites another attempt with better evidence. A
    // mismatch never should: no evidence makes the wrong gateway the right one.
    $payment = paymentOn(SpyingGateway::CODE);

    expect(fn () => $this->payments->verify($payment, $this->manual, $this->finance, ['reference' => 'BANK-X']))
        ->toThrow(GatewayMismatch::class);

    expect(is_a(GatewayMismatch::class, PaymentNotVerifiable::class, allow_string: true))->toBeFalse();
});

it('does not rewrite the payment\'s gateway to make verification succeed', function (): void {
    $payment = paymentOn(SpyingGateway::CODE);

    expect(fn () => $this->payments->verify($payment, $this->manual, $this->finance, ['reference' => 'BANK-Y']))
        ->toThrow(GatewayMismatch::class);

    expect($payment->fresh()->gateway)->toBe(SpyingGateway::CODE);
});

it('refuses again on the locked row when the caller\'s copy is stale', function (): void {
    // The pre-check reads the instance the caller handed over, which was loaded
    // at some earlier moment. Between that read and the settlement transaction
    // the row can change. This drives exactly that: the in-memory copy still
    // says test-other, the committed row says manual, and the recheck inside
    // the transaction is the only thing left to catch it.
    $payment = paymentOn(SpyingGateway::CODE);
    $gateway = SpyingGateway::permissive();

    DB::table('payments')->where('id', $payment->getKey())->update(['gateway' => ManualGateway::CODE]);

    expect($payment->gateway)->toBe(SpyingGateway::CODE);

    expect(fn () => $this->payments->verify($payment, $gateway, $this->finance, ['reference' => 'BANK-STALE']))
        ->toThrow(GatewayMismatch::class);

    // The pre-check passed on the stale copy, so the gateway was consulted;
    // the recheck on the locked row is what stopped the settlement.
    expect($gateway->verifyCallCount())->toBe(1);

    $fresh = $payment->fresh();

    expect($fresh->status)->toBe(PaymentStatus::Pending)
        ->and($fresh->paid_at)->toBeNull()
        ->and($fresh->provider_payment_id)->toBeNull()
        ->and($this->customer->fresh()->wallet_balance_toman)->toBe(0)
        ->and(WalletTransaction::query()->count())->toBe(0)
        ->and(Invoice::query()->count())->toBe(0)
        ->and(AuditLog::query()->where('event', AuditEvent::PaymentVerified)->count())->toBe(0);
});

it('still settles a payment through the gateway that owns it', function (): void {
    // The guard must not cost the working path.
    $payment = paymentOn(SpyingGateway::CODE, 640_000);
    $gateway = SpyingGateway::permissive();

    ['payment' => $settled, 'invoice' => $invoice] = $this->payments
        ->verify($payment, $gateway, $this->finance, ['reference' => 'BANK-OWNED']);

    expect($settled->status)->toBe(PaymentStatus::Paid)
        ->and($settled->provider_payment_id)->toBe('BANK-OWNED')
        ->and($gateway->verifyCallCount())->toBe(1)
        ->and($this->customer->fresh()->wallet_balance_toman)->toBe(640_000)
        ->and(WalletTransaction::query()->count())->toBe(1)
        ->and($invoice->amount_toman)->toBe(640_000)
        ->and(Invoice::query()->count())->toBe(1);
});

it('still settles a manual payment through the manual gateway', function (): void {
    $payment = $this->payments->createPayment(
        $this->customer, $this->manual, 310_000, (string) Str::uuid(),
    );

    ['payment' => $settled] = $this->payments
        ->verify($payment, $this->manual, $this->finance, ['reference' => 'BANK-MANUAL']);

    expect($settled->status)->toBe(PaymentStatus::Paid)
        ->and($this->customer->fresh()->wallet_balance_toman)->toBe(310_000);
});

it('lets two gateways use the same bank reference without colliding', function (): void {
    // The uniqueness that stops one transfer settling two payments is scoped to
    // the gateway. Now that a payment can only be settled by its own gateway,
    // that scope is meaningful rather than a hole.
    $manualPayment = $this->payments->createPayment(
        $this->customer, $this->manual, 100_000, (string) Str::uuid(),
    );
    $otherPayment = paymentOn(SpyingGateway::CODE, 200_000);

    $this->payments->verify($manualPayment, $this->manual, $this->finance, ['reference' => 'SHARED-REF']);
    $this->payments->verify($otherPayment, SpyingGateway::permissive(), $this->finance, ['reference' => 'SHARED-REF']);

    expect($this->customer->fresh()->wallet_balance_toman)->toBe(300_000)
        ->and(WalletTransaction::query()->count())->toBe(2);
});

it('refuses a mismatch before checking whether the reference is usable', function (): void {
    // No evidence, good or bad, changes the answer.
    $payment = paymentOn(SpyingGateway::CODE);

    expect(fn () => $this->payments->verify($payment, $this->manual, $this->finance, []))
        ->toThrow(GatewayMismatch::class);

    expect(fn () => $this->payments->verify($payment, $this->manual, $this->finance, ['reference' => '']))
        ->toThrow(GatewayMismatch::class);

    expectNothingSettled($payment);
});

it('checks the actor\'s permission before the gateway binding', function (): void {
    // Order matters here too: someone with no right to accept money learns
    // nothing about which gateway owns a payment.
    $payment = paymentOn(SpyingGateway::CODE);
    $support = User::factory()->create();
    $support->assignRole(AdminRole::Support->value);

    expect(fn () => $this->payments->verify($payment, $this->manual, $support, ['reference' => 'BANK-Z']))
        ->toThrow(App\Billing\Exceptions\UnauthorizedVerification::class);
});

it('will not invoice a payment that has not settled', function (): void {
    // An invoice asserts that a customer was charged and their wallet funded.
    // Issuing one for a pending payment would document money that never moved,
    // and this service is reachable from outside the settlement path.
    $payment = paymentOn(SpyingGateway::CODE);

    expect(fn () => app(InvoiceService::class)->issueForPayment($payment))
        ->toThrow(PaymentNotVerifiable::class);

    expect(Invoice::query()->count())->toBe(0);
});

it('will not invoice a rejected payment', function (): void {
    $payment = $this->payments->createPayment(
        $this->customer, $this->manual, 120_000, (string) Str::uuid(),
    );
    $rejected = $this->payments->reject($payment, $this->finance, 'No transfer found.');

    expect($rejected->status)->toBe(PaymentStatus::Failed)
        ->and(fn () => app(InvoiceService::class)->issueForPayment($rejected))
        ->toThrow(PaymentNotVerifiable::class);

    expect(Invoice::query()->count())->toBe(0);
});

it('invoices a settled payment', function (): void {
    $payment = $this->payments->createPayment(
        $this->customer, $this->manual, 480_000, (string) Str::uuid(),
    );
    ['payment' => $settled] = $this->payments
        ->verify($payment, $this->manual, $this->finance, ['reference' => 'BANK-INVOICE']);

    $invoice = app(InvoiceService::class)->issueForPayment($settled->fresh());

    expect($invoice->amount_toman)->toBe(480_000)
        ->and(Invoice::query()->count())->toBe(1);
});

it('issues exactly one invoice when settlement is replayed', function (): void {
    // The guard must not break the replay path, which reaches issueForPayment
    // with an already-settled payment.
    $payment = $this->payments->createPayment(
        $this->customer, $this->manual, 90_000, (string) Str::uuid(),
    );

    $first = $this->payments->verify($payment, $this->manual, $this->finance, ['reference' => 'BANK-REPLAY']);
    $second = $this->payments->verify($payment->fresh(), $this->manual, $this->finance, ['reference' => 'BANK-REPLAY']);

    expect($second['invoice']->getKey())->toBe($first['invoice']->getKey())
        ->and(Invoice::query()->count())->toBe(1)
        ->and(WalletTransaction::query()->count())->toBe(1)
        ->and($this->customer->fresh()->wallet_balance_toman)->toBe(90_000);
});
