<?php

declare(strict_types=1);

use App\Authorization\RoleProvisioner;
use App\Billing\Gateways\ManualGateway;
use App\Billing\InvoiceService;
use App\Billing\PaymentService;
use App\Enums\AdminRole;
use App\Enums\InvoiceStatus;
use App\Enums\InvoiceType;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function (): void {
    app(RoleProvisioner::class)->sync();

    $this->payments = app(PaymentService::class);
    $this->gateway = app(ManualGateway::class);
    $this->customer = User::factory()->fromTelegram()->create();
    $this->finance = User::factory()->create();
    $this->finance->assignRole(AdminRole::Finance->value);
});

function settle(int $amount = 500_000, string $reference = 'BANK-INV'): array
{
    $payment = test()->payments->createPayment(
        test()->customer, test()->gateway, $amount, (string) Str::uuid(),
    );

    return test()->payments->verify($payment, test()->gateway, test()->finance, ['reference' => $reference]);
}

it('issues one invoice for a settled payment', function (): void {
    ['invoice' => $invoice, 'payment' => $payment] = settle(750_000);

    expect($invoice->user_id)->toBe($this->customer->id)
        ->and($invoice->amount_toman)->toBe(750_000)
        ->and($invoice->type)->toBe(InvoiceType::WalletTopUp)
        ->and($invoice->status)->toBe(InvoiceStatus::Issued)
        ->and($invoice->issued_at)->not->toBeNull()
        ->and($invoice->number)->toBe(app(InvoiceService::class)->numberForPayment($payment))
        ->and(Invoice::query()->count())->toBe(1);
});

it('makes the line items add up to the invoice total', function (): void {
    // Integer arithmetic throughout. A total that disagrees with its own lines
    // is an invoice nobody can defend.
    ['invoice' => $invoice] = settle(1_234_567);

    expect($invoice->lineItemTotal())->toBe($invoice->amount_toman)
        ->and($invoice->line_items)->toHaveCount(1)
        ->and($invoice->line_items[0]['total_toman'])->toBe(1_234_567);
});

it('stores amounts as integers, never floats', function (): void {
    ['invoice' => $invoice] = settle(999_999_999_999);

    expect($invoice->fresh()->amount_toman)->toBeInt()->toBe(999_999_999_999)
        ->and($invoice->fresh()->line_items[0]['total_toman'])->toBeInt();
});

it('gives every invoice a unique number', function (): void {
    $first = settle(100_000, 'BANK-A')['invoice'];
    $second = settle(200_000, 'BANK-B')['invoice'];

    expect($second->number)->not->toBe($first->number)
        ->and(Invoice::query()->count())->toBe(2);
});

it('refuses a duplicate invoice number in the database', function (): void {
    $invoice = settle()['invoice'];

    expect(fn () => Invoice::query()->create([
        'user_id' => $this->customer->id,
        'number' => $invoice->number,
        'type' => InvoiceType::WalletTopUp,
        'amount_toman' => 1_000,
        'status' => InvoiceStatus::Issued,
        'issued_at' => now(),
        'line_items' => [],
    ]))->toThrow(QueryException::class);
});

it('reuses the existing invoice when issuing is repeated', function (): void {
    // Deterministic from the payment id, so a replay resolves to the same
    // document rather than drawing a second number for the same money.
    ['payment' => $payment, 'invoice' => $invoice] = settle();

    $again = app(InvoiceService::class)->issueForPayment($payment->fresh());

    expect($again->getKey())->toBe($invoice->getKey())
        ->and(Invoice::query()->count())->toBe(1);
});

it('records no invented exchange rate', function (): void {
    // Toman paid into a Toman wallet: no conversion happened, so recording one
    // would be a fabricated account of the transaction.
    ['invoice' => $invoice] = settle();

    expect($invoice->pricing_snapshot)->toBeNull();
});

it('stores issued_at in utc', function (): void {
    ['invoice' => $invoice] = settle();

    expect($invoice->issued_at->timezone->getName())->toBe('UTC')
        ->and(config('app.timezone'))->toBe('UTC');
});

it('keeps receipts and credentials out of the invoice', function (): void {
    $payment = $this->payments->createPayment(
        $this->customer, $this->gateway, 100_000, (string) Str::uuid(),
        receiptPath: 'receipts/private/statement.jpg',
        metadata: ['api_token' => 'live-secret-value'],
    );

    $result = $this->payments->verify($payment, $this->gateway, $this->finance, ['reference' => 'BANK-SAFE']);
    $serialised = json_encode($result['invoice']->toArray());

    expect($serialised)->not->toContain('live-secret-value')
        ->and($serialised)->not->toContain('receipts/private');
});

it('belongs to the customer who paid', function (): void {
    ['invoice' => $invoice] = settle();

    expect($invoice->user->is($this->customer))->toBeTrue();
});

it('keeps invoices when a user is removed', function (): void {
    settle();

    expect(fn () => DB::transaction(fn () => $this->customer->delete()))
        ->toThrow(QueryException::class);

    expect(Invoice::query()->count())->toBe(1);
});
