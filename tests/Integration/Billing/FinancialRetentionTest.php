<?php

declare(strict_types=1);

use App\Authorization\RoleProvisioner;
use App\Billing\Gateways\ManualGateway;
use App\Billing\PaymentService;
use App\Enums\AdminRole;
use App\Enums\InvoiceStatus;
use App\Enums\PaymentStatus;
use App\Exceptions\FinancialRecordDeletionForbidden;
use App\Models\Invoice;
use App\Models\Payment;
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

/** @return array{payment: Payment, invoice: Invoice} */
function retainedRecords(int $amount = 500_000, string $reference = 'BANK-RETAIN'): array
{
    $payment = test()->payments->createPayment(
        test()->customer, test()->gateway, $amount, (string) Str::uuid(),
    );

    return test()->payments->verify($payment, test()->gateway, test()->finance, ['reference' => $reference]);
}

it('refuses to delete a payment through eloquent', function (): void {
    // A payment row is the record of money a customer sent. Nothing
    // reconstructs it once it is gone.
    $payment = $this->payments->createPayment(
        $this->customer, $this->gateway, 100_000, (string) Str::uuid(),
    );

    expect(fn () => $payment->delete())->toThrow(FinancialRecordDeletionForbidden::class);

    expect(Payment::query()->whereKey($payment->getKey())->exists())->toBeTrue();
});

it('refuses to delete an invoice through eloquent', function (): void {
    ['invoice' => $invoice] = retainedRecords();

    expect(fn () => $invoice->delete())->toThrow(FinancialRecordDeletionForbidden::class);

    expect(Invoice::query()->whereKey($invoice->getKey())->exists())->toBeTrue();
});

it('refuses a raw sql delete from payments', function (): void {
    // The guard that still holds for a query builder call or a psql prompt,
    // which is where an accidental DELETE with a wrong WHERE actually happens.
    ['payment' => $payment] = retainedRecords();

    expect(fn () => DB::transaction(fn () => DB::table('payments')->where('id', $payment->getKey())->delete()))
        ->toThrow(QueryException::class);

    expect(fn () => DB::transaction(fn () => DB::table('payments')->delete()))
        ->toThrow(QueryException::class);

    expect(DB::table('payments')->count())->toBe(1);
});

it('refuses a raw sql delete from invoices', function (): void {
    ['invoice' => $invoice] = retainedRecords();

    expect(fn () => DB::transaction(fn () => DB::table('invoices')->where('id', $invoice->getKey())->delete()))
        ->toThrow(QueryException::class);

    expect(fn () => DB::transaction(fn () => DB::table('invoices')->delete()))
        ->toThrow(QueryException::class);

    expect(DB::table('invoices')->count())->toBe(1);
});

it('keeps the financial history after every attempt to remove it', function (): void {
    ['payment' => $payment, 'invoice' => $invoice] = retainedRecords(750_000, 'BANK-KEEP');

    foreach ([
        fn () => $payment->delete(),
        fn () => $invoice->delete(),
        fn () => DB::transaction(fn () => DB::table('payments')->delete()),
        fn () => DB::transaction(fn () => DB::table('invoices')->delete()),
    ] as $attempt) {
        try {
            $attempt();
        } catch (Throwable) {
            // Each is expected to fail; what matters is the state afterwards.
        }
    }

    expect(Payment::query()->count())->toBe(1)
        ->and(Invoice::query()->count())->toBe(1)
        ->and(Payment::query()->first()->amount_toman)->toBe(750_000)
        ->and(Invoice::query()->first()->amount_toman)->toBe(750_000)
        ->and($this->customer->fresh()->wallet_balance_toman)->toBe(750_000);
});

it('still lets a payment settle, which is an update', function (): void {
    // Retention is not immutability. A payment's status legitimately changes.
    $payment = $this->payments->createPayment(
        $this->customer, $this->gateway, 400_000, (string) Str::uuid(),
    );

    expect($payment->status)->toBe(PaymentStatus::Pending);

    ['payment' => $settled] = $this->payments
        ->verify($payment, $this->gateway, $this->finance, ['reference' => 'BANK-UPDATE']);

    expect($settled->status)->toBe(PaymentStatus::Paid)
        ->and($settled->paid_at)->not->toBeNull()
        ->and($settled->verified_by_admin_id)->toBe($this->finance->id);
});

it('still lets a payment be rejected, which is also an update', function (): void {
    $payment = $this->payments->createPayment(
        $this->customer, $this->gateway, 200_000, (string) Str::uuid(),
    );

    $rejected = $this->payments->reject($payment, $this->finance, 'No transfer found.');

    expect($rejected->status)->toBe(PaymentStatus::Failed);
});

it('still lets an invoice be updated', function (): void {
    // Invoices are retained, not frozen. Only one status exists so far, so
    // what is proved here is that the mechanism a later lifecycle will need is
    // still open: the row accepts an UPDATE. Making invoices append-only now
    // would foreclose that.
    ['invoice' => $invoice] = retainedRecords();
    $issuedAt = now()->subDay();

    $invoice->forceFill([
        'status' => InvoiceStatus::Issued,
        'issued_at' => $issuedAt,
    ])->save();

    expect($invoice->fresh()->issued_at->toDateTimeString())->toBe($issuedAt->toDateTimeString())
        ->and($invoice->fresh()->status)->toBe(InvoiceStatus::Issued);
});

it('allows a raw sql update on both tables', function (): void {
    ['payment' => $payment, 'invoice' => $invoice] = retainedRecords();

    $payments = DB::table('payments')->where('id', $payment->getKey())
        ->update(['gateway_fee_toman' => 1_500]);
    $invoices = DB::table('invoices')->where('id', $invoice->getKey())
        ->update(['status' => InvoiceStatus::Issued->value, 'updated_at' => now()]);

    expect($payments)->toBe(1)
        ->and($invoices)->toBe(1)
        ->and($payment->fresh()->gateway_fee_toman)->toBe(1_500);
});

it('installs a delete trigger on both tables and nothing more', function (): void {
    $triggers = DB::table('pg_trigger as t')
        ->join('pg_class as c', 'c.oid', '=', 't.tgrelid')
        ->whereIn('c.relname', ['payments', 'invoices'])
        ->where('t.tgisinternal', false)
        ->pluck('t.tgname')
        ->sort()
        ->values()
        ->all();

    expect($triggers)->toBe(['invoices_no_delete', 'payments_no_delete']);
});
