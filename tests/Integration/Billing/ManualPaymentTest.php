<?php

declare(strict_types=1);

use App\Audit\AuditEvent;
use App\Authorization\RoleProvisioner;
use App\Billing\Exceptions\PaymentIdempotencyConflict;
use App\Billing\Exceptions\PaymentNotVerifiable;
use App\Billing\Exceptions\UnauthorizedVerification;
use App\Billing\Gateways\ManualGateway;
use App\Billing\PaymentService;
use App\Enums\AdminRole;
use App\Enums\PaymentStatus;
use App\Models\AuditLog;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\User;
use App\Models\WalletTransaction;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

beforeEach(function (): void {
    app(RoleProvisioner::class)->sync();

    $this->payments = app(PaymentService::class);
    $this->gateway = app(ManualGateway::class);
    $this->customer = User::factory()->fromTelegram()->create();

    $this->finance = User::factory()->create();
    $this->finance->assignRole(AdminRole::Finance->value);
});

function newPayment(int $amount = 500_000): Payment
{
    return test()->payments->createPayment(
        test()->customer, test()->gateway, $amount, (string) Str::uuid(),
    );
}

it('declares itself as not automated', function (): void {
    // Callers rely on this to know that public, automated selling is not yet
    // possible with this gateway alone.
    expect($this->gateway->isAutomated())->toBeFalse()
        ->and($this->gateway->instructionsFor(newPayment())->requiresManualVerification)->toBeTrue();
});

it('never touches the network', function (): void {
    Http::preventStrayRequests();

    $payment = newPayment();
    $this->payments->verify($payment, $this->gateway, $this->finance, ['reference' => 'BANK-1']);

    Http::assertNothingSent();
});

it('creates a pending payment that moves no money', function (): void {
    // The hard invariant: a payment is a claim, not money.
    $payment = newPayment(750_000);

    expect($payment->status)->toBe(PaymentStatus::Pending)
        ->and($payment->paid_at)->toBeNull()
        ->and($payment->verified_by_admin_id)->toBeNull()
        ->and($this->customer->fresh()->wallet_balance_toman)->toBe(0)
        ->and(WalletTransaction::query()->count())->toBe(0)
        ->and(Invoice::query()->count())->toBe(0);
});

it('leaves the wallet untouched even with a receipt and a reference recorded', function (): void {
    $payment = $this->payments->createPayment(
        $this->customer, $this->gateway, 400_000, (string) Str::uuid(),
        receiptPath: 'receipts/2026/09/example.jpg',
        metadata: ['claimed_reference' => 'BANK-CLAIM-1'],
    );

    // Evidence supplied by a customer is not verification.
    expect($payment->receipt_path)->not->toBeNull()
        ->and($this->customer->fresh()->wallet_balance_toman)->toBe(0);
});

it('credits the wallet exactly once when finance verifies it', function (): void {
    $payment = newPayment(500_000);

    ['payment' => $settled, 'invoice' => $invoice] = $this->payments->verify(
        $payment, $this->gateway, $this->finance, ['reference' => 'BANK-REF-1'],
    );

    expect($settled->status)->toBe(PaymentStatus::Paid)
        ->and($settled->paid_at)->not->toBeNull()
        ->and($settled->verified_by_admin_id)->toBe($this->finance->id)
        ->and($settled->provider_payment_id)->toBe('BANK-REF-1')
        ->and($this->customer->fresh()->wallet_balance_toman)->toBe(500_000)
        ->and(WalletTransaction::query()->count())->toBe(1)
        ->and($invoice->amount_toman)->toBe(500_000);
});

it('refuses verification by support', function (): void {
    // Accepting money is a finance decision, not an operational one.
    $support = User::factory()->create();
    $support->assignRole(AdminRole::Support->value);
    $payment = newPayment();

    expect(fn () => $this->payments->verify($payment, $this->gateway, $support, ['reference' => 'BANK-2']))
        ->toThrow(UnauthorizedVerification::class);

    expect($this->customer->fresh()->wallet_balance_toman)->toBe(0)
        ->and($payment->fresh()->status)->toBe(PaymentStatus::Pending);
});

it('refuses verification by an ordinary customer', function (): void {
    $payment = newPayment();

    expect(fn () => $this->payments->verify(
        $payment, $this->gateway, User::factory()->fromTelegram()->create(), ['reference' => 'BANK-3'],
    ))->toThrow(UnauthorizedVerification::class);

    expect($this->customer->fresh()->wallet_balance_toman)->toBe(0);
});

it('refuses verification by a suspended administrator', function (): void {
    $suspended = User::factory()->suspended()->create();
    $suspended->assignRole(AdminRole::Finance->value);
    $payment = newPayment();

    expect(fn () => $this->payments->verify($payment, $this->gateway, $suspended, ['reference' => 'BANK-4']))
        ->toThrow(UnauthorizedVerification::class);
});

it('lets an owner verify', function (): void {
    $owner = User::factory()->create();
    $owner->assignRole(AdminRole::Owner->value);

    $this->payments->verify(newPayment(), $this->gateway, $owner, ['reference' => 'BANK-OWNER']);

    expect($this->customer->fresh()->wallet_balance_toman)->toBe(500_000);
});

it('refuses a verification with no reference', function (string $reference): void {
    // Without a reference the payment cannot be traced to a real transfer, and
    // nothing stops the same money settling twice.
    $payment = newPayment();

    expect(fn () => $this->payments->verify($payment, $this->gateway, $this->finance, ['reference' => $reference]))
        ->toThrow(PaymentNotVerifiable::class);

    expect($this->customer->fresh()->wallet_balance_toman)->toBe(0);
})->with(['', '   ']);

it('credits nothing when a payment is rejected', function (): void {
    $payment = newPayment();

    $rejected = $this->payments->reject($payment, $this->finance, 'Receipt did not match');

    expect($rejected->status)->toBe(PaymentStatus::Failed)
        ->and($this->customer->fresh()->wallet_balance_toman)->toBe(0)
        ->and(WalletTransaction::query()->count())->toBe(0)
        ->and(Invoice::query()->count())->toBe(0);
});

it('credits once and issues one invoice when verification is replayed', function (): void {
    $payment = newPayment(300_000);

    $first = $this->payments->verify($payment, $this->gateway, $this->finance, ['reference' => 'BANK-REPLAY']);
    $second = $this->payments->verify($payment->fresh(), $this->gateway, $this->finance, ['reference' => 'BANK-REPLAY']);

    expect($second['invoice']->getKey())->toBe($first['invoice']->getKey())
        ->and($this->customer->fresh()->wallet_balance_toman)->toBe(300_000)
        ->and(WalletTransaction::query()->count())->toBe(1)
        ->and(Invoice::query()->count())->toBe(1)
        ->and(AuditLog::query()->where('event', AuditEvent::PaymentVerified)->count())->toBe(1);
});

it('returns the existing payment when a key is replayed exactly', function (): void {
    $key = (string) Str::uuid();

    $first = $this->payments->createPayment($this->customer, $this->gateway, 200_000, $key);
    $second = $this->payments->createPayment($this->customer, $this->gateway, 200_000, $key);

    expect($second->getKey())->toBe($first->getKey())
        ->and(Payment::query()->count())->toBe(1);
});

it('fails closed when a payment key is reused for a different request', function (array $change): void {
    $key = (string) Str::uuid();
    $this->payments->createPayment($this->customer, $this->gateway, 200_000, $key);

    $user = $change['user'] === 'other' ? User::factory()->fromTelegram()->create() : $this->customer;

    expect(fn () => $this->payments->createPayment($user, $this->gateway, $change['amount'], $key))
        ->toThrow(PaymentIdempotencyConflict::class);
})->with([
    'different amount' => [['amount' => 250_000, 'user' => 'same']],
    'different user' => [['amount' => 200_000, 'user' => 'other']],
]);

it('enforces the payment idempotency key in the database', function (): void {
    $this->payments->createPayment($this->customer, $this->gateway, 100_000, 'fixed-payment-key');

    expect(fn () => Payment::query()->create([
        'user_id' => $this->customer->id,
        'gateway' => ManualGateway::CODE,
        'amount_toman' => 1,
        'idempotency_key' => 'fixed-payment-key',
    ]))->toThrow(QueryException::class);
});

it('will not let one bank reference settle two payments', function (): void {
    // The same real-world transfer must not fund two wallets, and the database
    // is what guarantees it when two verifications race.
    $first = newPayment(100_000);
    $second = newPayment(100_000);

    $this->payments->verify($first, $this->gateway, $this->finance, ['reference' => 'BANK-SHARED']);

    expect(fn () => $this->payments->verify($second, $this->gateway, $this->finance, ['reference' => 'BANK-SHARED']))
        ->toThrow(PaymentNotVerifiable::class);

    expect($this->customer->fresh()->wallet_balance_toman)->toBe(100_000)
        ->and($second->fresh()->status)->toBe(PaymentStatus::Pending)
        ->and(Invoice::query()->count())->toBe(1);
});

it('allows many payments to have no reference yet', function (): void {
    // A partial unique index: pending payments all have a null reference.
    newPayment();
    newPayment();
    newPayment();

    expect(Payment::query()->whereNull('provider_payment_id')->count())->toBe(3);
});

it('rejects a non-positive payment amount', function (int $amount): void {
    expect(fn () => $this->payments->createPayment($this->customer, $this->gateway, $amount, (string) Str::uuid()))
        ->toThrow(InvalidArgumentException::class);
})->with([0, -1]);

it('rejects a non-positive amount at the database too', function (): void {
    expect(fn () => Payment::query()->create([
        'user_id' => $this->customer->id,
        'gateway' => ManualGateway::CODE,
        'amount_toman' => 0,
        'idempotency_key' => (string) Str::uuid(),
    ]))->toThrow(QueryException::class);
});

it('rejects a negative gateway fee', function (): void {
    expect(fn () => Payment::query()->create([
        'user_id' => $this->customer->id,
        'gateway' => ManualGateway::CODE,
        'amount_toman' => 1_000,
        'gateway_fee_toman' => -1,
        'idempotency_key' => (string) Str::uuid(),
    ]))->toThrow(QueryException::class);
});

it('handles payment amounts beyond the 32-bit range', function (): void {
    $large = 8_000_000_000_000;
    $payment = newPayment($large);

    $this->payments->verify($payment, $this->gateway, $this->finance, ['reference' => 'BANK-LARGE']);

    expect($this->customer->fresh()->wallet_balance_toman)->toBe($large);
});

it('keeps secrets out of stored payment metadata', function (): void {
    $payment = $this->payments->createPayment(
        $this->customer, $this->gateway, 100_000, (string) Str::uuid(),
        metadata: ['channel' => 'bank_transfer', 'api_token' => 'live-secret-value'],
    );

    $stored = json_encode($payment->fresh()->request_metadata);

    expect($stored)->not->toContain('live-secret-value')
        ->and($stored)->toContain('bank_transfer');
});

it('audits the verification without recording a secret', function (): void {
    $payment = newPayment();
    $this->payments->verify($payment, $this->gateway, $this->finance, [
        'reference' => 'BANK-AUDIT', 'note' => 'checked against statement',
    ]);

    $entry = AuditLog::query()->where('event', AuditEvent::PaymentVerified)->sole();

    expect((int) $entry->actor_id)->toBe($this->finance->id)
        ->and($entry->metadata['reference'])->toBe('BANK-AUDIT')
        ->and($entry->metadata['amount_toman'])->toBe(500_000);
});

it('keeps payment history when a user is removed', function (): void {
    newPayment();

    expect(fn () => DB::transaction(fn () => $this->customer->delete()))
        ->toThrow(QueryException::class);

    expect(Payment::query()->count())->toBe(1);
});
