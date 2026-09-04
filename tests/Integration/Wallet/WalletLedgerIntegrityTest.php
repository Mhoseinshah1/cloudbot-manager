<?php

declare(strict_types=1);

use App\Enums\WalletTransactionType;
use App\Exceptions\WalletLedgerIsImmutable;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Wallet\WalletService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

beforeEach(function (): void {
    $this->wallet = app(WalletService::class);
    $this->customer = User::factory()->fromTelegram()->create();
});

it('refuses a ledger update through the model', function (): void {
    $transaction = $this->wallet->credit($this->customer, 10_000, (string) Str::uuid(), 'Top-up');

    expect(fn () => $transaction->update(['amount_toman' => 999]))
        ->toThrow(WalletLedgerIsImmutable::class);
});

it('refuses a ledger delete through the model', function (): void {
    $transaction = $this->wallet->credit($this->customer, 10_000, (string) Str::uuid(), 'Top-up');

    expect(fn () => $transaction->delete())->toThrow(WalletLedgerIsImmutable::class);
});

it('refuses a ledger update issued straight to postgresql', function (): void {
    // The model guard is bypassed by any query builder call. This is the guard
    // that still holds, and the one that matters when money is involved.
    $this->wallet->credit($this->customer, 10_000, (string) Str::uuid(), 'Top-up');

    expect(fn () => DB::table('wallet_transactions')->update(['amount_toman' => 999_999]))
        ->toThrow(QueryException::class);
});

it('refuses a ledger delete issued straight to postgresql', function (): void {
    $this->wallet->credit($this->customer, 10_000, (string) Str::uuid(), 'Top-up');

    expect(fn () => DB::table('wallet_transactions')->delete())->toThrow(QueryException::class);
});

it('leaves the entry intact after a rejected tamper attempt', function (): void {
    $transaction = $this->wallet->credit($this->customer, 10_000, (string) Str::uuid(), 'Top-up');

    try {
        // Nested, so the rejection rolls back to a savepoint and the assertion
        // below can still run.
        DB::transaction(function () use ($transaction): void {
            DB::table('wallet_transactions')->where('id', $transaction->id)->update(['amount_toman' => 1]);
        });
    } catch (QueryException) {
        // expected
    }

    expect(WalletTransaction::query()->find($transaction->id)->amount_toman)->toBe(10_000);
});

it('rejects a ledger row whose arithmetic does not add up', function (): void {
    // The database checks this rather than trusting the application: a ledger
    // whose rows do not add up cannot be audited.
    expect(fn () => DB::table('wallet_transactions')->insert([
        'user_id' => $this->customer->id,
        'type' => WalletTransactionType::Credit->value,
        'amount_toman' => 100,
        'balance_before_toman' => 0,
        'balance_after_toman' => 500,
        'idempotency_key' => (string) Str::uuid(),
        'description' => 'inconsistent',
        'created_at' => now(),
    ]))->toThrow(QueryException::class);
});

it('rejects a zero-value ledger row', function (): void {
    expect(fn () => DB::table('wallet_transactions')->insert([
        'user_id' => $this->customer->id,
        'type' => WalletTransactionType::Credit->value,
        'amount_toman' => 0,
        'balance_before_toman' => 0,
        'balance_after_toman' => 0,
        'idempotency_key' => (string) Str::uuid(),
        'description' => 'nothing happened',
        'created_at' => now(),
    ]))->toThrow(QueryException::class);
});

it('rejects a negative resulting balance in the ledger', function (): void {
    expect(fn () => DB::table('wallet_transactions')->insert([
        'user_id' => $this->customer->id,
        'type' => WalletTransactionType::Debit->value,
        'amount_toman' => -100,
        'balance_before_toman' => 0,
        'balance_after_toman' => -100,
        'idempotency_key' => (string) Str::uuid(),
        'description' => 'overdraft',
        'created_at' => now(),
    ]))->toThrow(QueryException::class);
});

it('rejects a sign that contradicts the type', function (array $row): void {
    // A credit that subtracts, or a debit that adds, would make the ledger
    // unreadable at a glance and mask a bug as a legitimate entry.
    expect(fn () => DB::table('wallet_transactions')->insert(array_merge([
        'user_id' => $this->customer->id,
        'balance_before_toman' => 1_000,
        'idempotency_key' => (string) Str::uuid(),
        'description' => 'wrong sign',
        'created_at' => now(),
    ], $row)))->toThrow(QueryException::class);
})->with([
    'credit that subtracts' => [['type' => 'credit', 'amount_toman' => -100, 'balance_after_toman' => 900]],
    'debit that adds' => [['type' => 'debit', 'amount_toman' => 100, 'balance_after_toman' => 1_100]],
    'refund that subtracts' => [['type' => 'refund', 'amount_toman' => -100, 'balance_after_toman' => 900]],
]);

it('keeps the ledger when a user is removed', function (): void {
    // Financial history must outlive the account. The foreign key restricts
    // rather than cascades, so the attempt fails loudly.
    $this->wallet->credit($this->customer, 10_000, (string) Str::uuid(), 'Top-up');

    // Nested so the rejection rolls back to a savepoint; PostgreSQL aborts a
    // transaction outright on error and the assertion below still has to run.
    expect(fn () => DB::transaction(fn () => $this->customer->delete()))
        ->toThrow(QueryException::class);

    expect(WalletTransaction::query()->count())->toBe(1)
        ->and(User::query()->find($this->customer->id))->not->toBeNull();
});

it('stores no updated_at, because entries never change', function (): void {
    expect(Schema::hasColumn('wallet_transactions', 'updated_at'))->toBeFalse()
        ->and(Schema::hasColumn('wallet_transactions', 'created_at'))->toBeTrue();
});
