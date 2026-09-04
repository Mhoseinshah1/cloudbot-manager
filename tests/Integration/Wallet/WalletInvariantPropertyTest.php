<?php

declare(strict_types=1);

use App\Authorization\RoleProvisioner;
use App\Enums\AdminRole;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Wallet\Exceptions\InsufficientBalance;
use App\Wallet\WalletService;
use Illuminate\Support\Str;

/**
 * Randomized sequences of movements, checking the invariant after every one.
 *
 * Hand-written cases test the paths someone thought of. These run long, mixed
 * sequences to reach the ones nobody did — the awkward orderings where a debit
 * lands exactly on zero, or an adjustment follows a refund.
 *
 * Seeded, so a failure can be reproduced exactly rather than being a story
 * about a run nobody can repeat.
 */
it('keeps the balance equal to the ledger through a random sequence', function (int $seed): void {
    mt_srand($seed);

    app(RoleProvisioner::class)->sync();
    $wallet = app(WalletService::class);

    $finance = User::factory()->create();
    $finance->assignRole(AdminRole::Finance->value);

    $customer = User::factory()->fromTelegram()->create();
    $expected = 0;
    $applied = 0;

    for ($step = 0; $step < 40; $step++) {
        $customer = $customer->fresh();
        $balance = $customer->wallet_balance_toman;
        $key = (string) Str::uuid();
        $amount = mt_rand(1, 500_000);

        try {
            switch (mt_rand(1, 4)) {
                case 1:
                    $wallet->credit($customer, $amount, $key, 'Random credit');
                    $expected += $amount;
                    break;
                case 2:
                    $wallet->debit($customer, $amount, $key, 'Random debit');
                    $expected -= $amount;
                    break;
                case 3:
                    $wallet->refund($customer, $amount, $key, 'Random refund');
                    $expected += $amount;
                    break;
                default:
                    $signed = mt_rand(0, 1) === 1 ? $amount : -$amount;
                    $wallet->adjust($customer, $signed, $key, 'Random adjustment', $finance);
                    $expected += $signed;
                    break;
            }

            $applied++;
        } catch (InsufficientBalance) {
            // A refused movement must change nothing at all.
            expect($customer->fresh()->wallet_balance_toman)->toBe($balance);

            continue;
        }

        // After every successful movement, not merely at the end: a sequence
        // that ends correct could still have passed through a wrong state.
        $stored = $customer->fresh()->wallet_balance_toman;
        $ledger = (int) WalletTransaction::query()->where('user_id', $customer->id)->sum('amount_toman');

        expect($stored)->toBe($expected)
            ->and($ledger)->toBe($expected)
            ->and($stored)->toBeGreaterThanOrEqual(0);
    }

    // Every individual row must also add up on its own.
    foreach (WalletTransaction::query()->where('user_id', $customer->id)->get() as $row) {
        expect($row->balance_after_toman)->toBe($row->balance_before_toman + $row->amount_toman);
    }

    expect($applied)->toBeGreaterThan(0);

    $this->artisan('wallet:verify-integrity')->assertExitCode(0);
})->with([1, 7, 42, 1337, 20260904]);
