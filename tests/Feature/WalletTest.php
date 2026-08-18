<?php

use App\Exceptions\InsufficientWalletBalanceException;
use App\Models\Payment;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Services\WalletService;

beforeEach(function () {
    $this->user = User::factory()->create();
});

it('credits a wallet and records the transaction with the new balance', function () {
    $transaction = app(WalletService::class)->credit($this->user, 100000, 'Top up');

    expect($transaction->type)->toBe(WalletTransaction::TYPE_CREDIT);
    expect($transaction->amount_toman)->toBe(100000);
    expect($transaction->balance_after)->toBe(100000);

    $wallet = $this->user->wallet->fresh();
    expect($wallet)->not->toBeNull();
    expect($wallet->balance_toman)->toBe(100000);
});

it('debits a wallet and records the transaction with the new balance', function () {
    $service = app(WalletService::class);
    $service->credit($this->user, 100000);

    $transaction = $service->debit($this->user, 25000, 'Hourly usage');

    expect($transaction->type)->toBe(WalletTransaction::TYPE_DEBIT);
    expect($transaction->amount_toman)->toBe(25000);
    expect($transaction->balance_after)->toBe(75000);
    expect($this->user->wallet->fresh()->balance_toman)->toBe(75000);
});

it('never lets a wallet go negative', function () {
    $service = app(WalletService::class);
    $service->credit($this->user, 100);

    $service->debit($this->user, 101, 'Overdraft');
})->throws(InsufficientWalletBalanceException::class);

it('keeps a wallet consistent when a debit is rejected', function () {
    $service = app(WalletService::class);
    $service->credit($this->user, 100);

    try {
        $service->debit($this->user, 500, 'Overdraft');
        $this->fail('Expected InsufficientWalletBalanceException.');
    } catch (InsufficientWalletBalanceException) {
        // expected
    }

    expect($this->user->wallet->fresh()->balance_toman)->toBe(100);
    expect($this->user->wallet->transactions()->count())->toBe(1);
});

it('reuses the existing wallet for a user', function () {
    $service = app(WalletService::class);
    $service->credit($this->user, 1000);
    $walletId = $this->user->wallet->id;

    $service->credit($this->user, 2000);

    expect($this->user->wallet->fresh()->id)->toBe($walletId);
    expect(Wallet::query()->where('user_id', $this->user->id)->count())->toBe(1);
});

it('links transactions to an optional reference', function () {
    $payment = Payment::query()->create([
        'payment_uuid' => (string) Str::uuid(),
        'user_id' => $this->user->id,
        'gateway_code' => 'manual',
        'amount_toman' => 850,
        'status' => Payment::STATUS_PAID,
    ]);

    $transaction = app(WalletService::class)->credit($this->user, 850, 'Payment', $payment);

    expect($transaction->reference_type)->toBe(Payment::class);
    expect($transaction->reference_id)->toBe($payment->id);
});
