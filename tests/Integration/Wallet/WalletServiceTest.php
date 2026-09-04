<?php

declare(strict_types=1);

use App\Audit\AuditEvent;
use App\Authorization\RoleProvisioner;
use App\Enums\AdminRole;
use App\Enums\WalletTransactionType;
use App\Models\AuditLog;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Wallet\Exceptions\IdempotencyConflict;
use App\Wallet\Exceptions\InsufficientBalance;
use App\Wallet\Exceptions\InvalidWalletAmount;
use App\Wallet\Exceptions\UnauthorizedAdjustment;
use App\Wallet\WalletService;
use Illuminate\Support\Str;

beforeEach(function (): void {
    app(RoleProvisioner::class)->sync();

    $this->wallet = app(WalletService::class);
    $this->customer = User::factory()->fromTelegram()->create();
});

function walletKey(string $prefix = 'op'): string
{
    return $prefix.'-'.Str::uuid();
}

it('credits a wallet and records the movement', function (): void {
    $transaction = $this->wallet->credit($this->customer, 250_000, walletKey(), 'Top-up');

    expect($this->customer->fresh()->wallet_balance_toman)->toBe(250_000)
        ->and($transaction->type)->toBe(WalletTransactionType::Credit)
        ->and($transaction->amount_toman)->toBe(250_000)
        ->and($transaction->balance_before_toman)->toBe(0)
        ->and($transaction->balance_after_toman)->toBe(250_000);
});

it('debits a wallet, storing the amount negative', function (): void {
    $this->wallet->credit($this->customer, 300_000, walletKey(), 'Top-up');

    $transaction = $this->wallet->debit($this->customer->fresh(), 120_000, walletKey(), 'Purchase');

    expect($this->customer->fresh()->wallet_balance_toman)->toBe(180_000)
        // Negative so the ledger sums to the balance without interpreting types.
        ->and($transaction->amount_toman)->toBe(-120_000)
        ->and($transaction->balance_after_toman)->toBe(180_000);
});

it('refuses a debit larger than the balance and writes nothing', function (): void {
    $this->wallet->credit($this->customer, 100_000, walletKey(), 'Top-up');

    expect(fn () => $this->wallet->debit($this->customer->fresh(), 100_001, walletKey(), 'Too much'))
        ->toThrow(InsufficientBalance::class);

    // Nothing partial: the balance stands and no debit row was written.
    expect($this->customer->fresh()->wallet_balance_toman)->toBe(100_000)
        ->and(WalletTransaction::query()->where('type', 'debit')->count())->toBe(0);
});

it('allows a debit that empties the wallet exactly', function (): void {
    $this->wallet->credit($this->customer, 75_000, walletKey(), 'Top-up');
    $this->wallet->debit($this->customer->fresh(), 75_000, walletKey(), 'Exact spend');

    expect($this->customer->fresh()->wallet_balance_toman)->toBe(0);
});

it('refunds money to a wallet', function (): void {
    $this->wallet->credit($this->customer, 100_000, walletKey(), 'Top-up');
    $transaction = $this->wallet->refund($this->customer->fresh(), 40_000, walletKey(), 'Refund');

    expect($this->customer->fresh()->wallet_balance_toman)->toBe(140_000)
        ->and($transaction->type)->toBe(WalletTransactionType::Refund);
});

it('rejects a zero or negative amount', function (string $method): void {
    expect(fn () => $this->wallet->{$method}($this->customer, 0, walletKey(), 'Nothing'))
        ->toThrow(InvalidWalletAmount::class)
        ->and(fn () => $this->wallet->{$method}($this->customer, -5, walletKey(), 'Negative'))
        ->toThrow(InvalidWalletAmount::class);
})->with(['credit', 'debit', 'refund']);

it('handles amounts beyond the 32-bit range', function (): void {
    // Ordinary Toman balances pass 2^31. A 32-bit column would overflow here.
    $large = 9_000_000_000_000;

    $this->wallet->credit($this->customer, $large, walletKey(), 'Large top-up');

    expect($this->customer->fresh()->wallet_balance_toman)->toBe($large)
        ->and(WalletTransaction::query()->sum('amount_toman'))->toEqual($large);
});

it('returns the same transaction when a key is replayed exactly', function (): void {
    $idempotencyKey = walletKey();

    $first = $this->wallet->credit($this->customer, 60_000, $idempotencyKey, 'Top-up');
    $second = $this->wallet->credit($this->customer->fresh(), 60_000, $idempotencyKey, 'Top-up');

    expect($second->getKey())->toBe($first->getKey())
        // Credited once, one row.
        ->and($this->customer->fresh()->wallet_balance_toman)->toBe(60_000)
        ->and(WalletTransaction::query()->count())->toBe(1);
});

it('fails closed when a key is reused for a different operation', function (array $second): void {
    // Returning the earlier result here would tell the caller their request
    // succeeded while something else entirely is what happened.
    $idempotencyKey = walletKey();
    $other = User::factory()->fromTelegram()->create();
    $this->wallet->credit($this->customer, 60_000, $idempotencyKey, 'Top-up');

    $subject = $second['user'] === 'other' ? $other : $this->customer->fresh();

    expect(fn () => $this->wallet->{$second['method']}($subject, $second['amount'], $idempotencyKey, 'Replay'))
        ->toThrow(IdempotencyConflict::class);
})->with([
    'different amount' => [['method' => 'credit', 'amount' => 61_000, 'user' => 'same']],
    'different type' => [['method' => 'refund', 'amount' => 60_000, 'user' => 'same']],
    'different user' => [['method' => 'credit', 'amount' => 60_000, 'user' => 'other']],
]);

it('fails closed when a replay names a different reference', function (): void {
    $idempotencyKey = walletKey();
    $reference = User::factory()->create();

    $this->wallet->credit($this->customer, 60_000, $idempotencyKey, 'Top-up', $reference);

    expect(fn () => $this->wallet->credit(
        $this->customer->fresh(), 60_000, $idempotencyKey, 'Top-up', User::factory()->create(),
    ))->toThrow(IdempotencyConflict::class);
});

it('enforces the idempotency key in the database', function (): void {
    // Not merely an application lookup: two concurrent callers both check
    // before either writes.
    $this->wallet->credit($this->customer, 10_000, 'fixed-key', 'Top-up');

    expect(fn () => WalletTransaction::query()->create([
        'user_id' => $this->customer->id,
        'type' => WalletTransactionType::Credit,
        'amount_toman' => 1,
        'balance_before_toman' => 0,
        'balance_after_toman' => 1,
        'idempotency_key' => 'fixed-key',
        'description' => 'duplicate',
    ]))->toThrow(Illuminate\Database\QueryException::class);
});

it('lets a finance administrator adjust a balance with a reason', function (): void {
    $finance = User::factory()->create();
    $finance->assignRole(AdminRole::Finance->value);

    $this->wallet->credit($this->customer, 50_000, walletKey(), 'Top-up');
    $transaction = $this->wallet->adjust(
        $this->customer->fresh(), -20_000, walletKey(), 'Goodwill correction after billing error', $finance,
    );

    expect($this->customer->fresh()->wallet_balance_toman)->toBe(30_000)
        ->and($transaction->type)->toBe(WalletTransactionType::Adjustment)
        ->and($transaction->description)->toContain('billing error');
});

it('refuses an adjustment from support', function (): void {
    // Support handles customers and servers. Moving money is a finance
    // decision, and this is the assertion that keeps that true.
    $support = User::factory()->create();
    $support->assignRole(AdminRole::Support->value);

    expect(fn () => $this->wallet->adjust($this->customer, 10_000, walletKey(), 'Because', $support))
        ->toThrow(UnauthorizedAdjustment::class);

    expect($this->customer->fresh()->wallet_balance_toman)->toBe(0);
});

it('refuses an adjustment from an ordinary customer', function (): void {
    expect(fn () => $this->wallet->adjust(
        $this->customer, 10_000, walletKey(), 'Give me money', User::factory()->fromTelegram()->create(),
    ))->toThrow(UnauthorizedAdjustment::class);
});

it('refuses an adjustment from a suspended administrator', function (): void {
    $finance = User::factory()->suspended()->create();
    $finance->assignRole(AdminRole::Finance->value);

    expect(fn () => $this->wallet->adjust($this->customer, 10_000, walletKey(), 'Because', $finance))
        ->toThrow(UnauthorizedAdjustment::class);
});

it('refuses an adjustment with no reason', function (): void {
    // An adjustment nobody explained is unreviewable afterwards.
    $finance = User::factory()->create();
    $finance->assignRole(AdminRole::Finance->value);

    expect(fn () => $this->wallet->adjust($this->customer, 10_000, walletKey(), '   ', $finance))
        ->toThrow(UnauthorizedAdjustment::class);
});

it('refuses an adjustment that would go below zero', function (): void {
    $finance = User::factory()->create();
    $finance->assignRole(AdminRole::Finance->value);
    $this->wallet->credit($this->customer, 10_000, walletKey(), 'Top-up');

    expect(fn () => $this->wallet->adjust($this->customer->fresh(), -10_001, walletKey(), 'Too far', $finance))
        ->toThrow(InsufficientBalance::class);

    expect($this->customer->fresh()->wallet_balance_toman)->toBe(10_000);
});

it('audits every kind of movement', function (): void {
    $finance = User::factory()->create();
    $finance->assignRole(AdminRole::Finance->value);

    $this->wallet->credit($this->customer, 100_000, walletKey(), 'Top-up');
    $this->wallet->debit($this->customer->fresh(), 10_000, walletKey(), 'Spend');
    $this->wallet->refund($this->customer->fresh(), 5_000, walletKey(), 'Refund');
    $this->wallet->adjust($this->customer->fresh(), 1_000, walletKey(), 'Correction', $finance);

    foreach ([AuditEvent::WalletCredit, AuditEvent::WalletDebit, AuditEvent::WalletRefund, AuditEvent::WalletAdjusted] as $event) {
        expect(AuditLog::query()->where('event', $event)->count())->toBe(1, $event);
    }
});

it('records before and after balances in the audit entry', function (): void {
    $this->wallet->credit($this->customer, 100_000, walletKey(), 'Top-up');
    $this->wallet->debit($this->customer->fresh(), 30_000, walletKey(), 'Spend');

    $entry = AuditLog::query()->where('event', AuditEvent::WalletDebit)->sole();

    expect($entry->before)->toBe(['balance_toman' => 100_000])
        ->and($entry->after)->toBe(['balance_toman' => 70_000])
        ->and($entry->metadata['amount_toman'])->toBe(-30_000);
});
