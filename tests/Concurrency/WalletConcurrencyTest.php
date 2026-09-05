<?php

declare(strict_types=1);

use App\Models\User;
use App\Wallet\Exceptions\IdempotencyConflict;
use App\Wallet\Exceptions\InsufficientBalance;
use App\Wallet\WalletService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\Concurrency\ForkedWorkers;

/**
 * Real concurrency, in real processes, against real PostgreSQL.
 *
 * These do not use RefreshDatabase: the rows have to be committed for another
 * process to see them at all. Each test clears the financial tables itself.
 * TRUNCATE is used rather than DELETE because the ledger's append-only trigger
 * refuses row deletions — which is the point of it.
 */
beforeEach(function (): void {
    // Ordered for foreign keys; RESTART IDENTITY keeps ids predictable.
    DB::statement('TRUNCATE wallet_transactions, invoices, payments, audit_logs RESTART IDENTITY CASCADE');
    DB::table('users')->delete();

    $this->customer = User::factory()->fromTelegram()->create();
});

afterEach(function (): void {
    DB::statement('TRUNCATE wallet_transactions, invoices, payments, audit_logs RESTART IDENTITY CASCADE');
    DB::table('users')->delete();
});

it('loses no credit when many processes credit at once', function (): void {
    // Each worker credits a distinct amount under its own key. Every one must
    // land: a lost update here is money a customer paid and never received.
    $userId = $this->customer->id;
    $amounts = [11_000, 22_000, 33_000, 44_000, 55_000, 66_000];

    $results = ForkedWorkers::run(count($amounts), function (int $index) use ($userId, $amounts): array {
        $user = User::query()->findOrFail($userId);

        app(WalletService::class)->credit(
            $user, $amounts[$index], 'concurrent-credit-'.$index, 'Concurrent top-up',
        );

        return ['ok' => true];
    });

    foreach ($results as $result) {
        expect($result['error'])->toBeNull()
            ->and($result['ok'])->toBeTrue();
    }

    $expected = array_sum($amounts);

    expect((int) DB::table('users')->where('id', $userId)->value('wallet_balance_toman'))->toBe($expected)
        ->and((int) DB::table('wallet_transactions')->where('user_id', $userId)->sum('amount_toman'))->toBe($expected)
        ->and(DB::table('wallet_transactions')->where('user_id', $userId)->count())->toBe(count($amounts));
});

it('lets only one of two concurrent debits spend the same funds', function (): void {
    // The canonical double-spend. With 1000 available and two simultaneous
    // requests for 700, exactly one may succeed — otherwise the customer has
    // spent money that was never there.
    $userId = $this->customer->id;
    app(WalletService::class)->credit(
        User::query()->findOrFail($userId), 1_000, (string) Str::uuid(), 'Seed',
    );

    $results = ForkedWorkers::run(2, function (int $index) use ($userId): array {
        $user = User::query()->findOrFail($userId);

        try {
            app(WalletService::class)->debit($user, 700, 'concurrent-debit-'.$index, 'Concurrent spend');

            return ['ok' => true, 'outcome' => 'succeeded'];
        } catch (InsufficientBalance) {
            return ['ok' => true, 'outcome' => 'refused'];
        }
    });

    $outcomes = array_column($results, 'outcome');

    expect(array_count_values($outcomes)['succeeded'] ?? 0)->toBe(1)
        ->and(array_count_values($outcomes)['refused'] ?? 0)->toBe(1)
        ->and((int) DB::table('users')->where('id', $userId)->value('wallet_balance_toman'))->toBe(300)
        ->and(DB::table('wallet_transactions')->where('type', 'debit')->count())->toBe(1);
});

it('applies a shared idempotency key exactly once under contention', function (): void {
    // Four processes, one key, one intended movement. The row lock serialises
    // them and the losers find the winner's entry instead of writing their own.
    $userId = $this->customer->id;
    $sharedKey = 'concurrent-shared-'.Str::uuid();

    $results = ForkedWorkers::run(4, function () use ($userId, $sharedKey): array {
        $user = User::query()->findOrFail($userId);

        app(WalletService::class)->credit($user, 250_000, $sharedKey, 'Shared key credit');

        return ['ok' => true];
    });

    foreach ($results as $result) {
        expect($result['error'])->toBeNull();
    }

    expect((int) DB::table('users')->where('id', $userId)->value('wallet_balance_toman'))->toBe(250_000)
        ->and(DB::table('wallet_transactions')->where('idempotency_key', $sharedKey)->count())->toBe(1)
        ->and(DB::table('wallet_transactions')->count())->toBe(1);
});

it('refuses a shared key used for different customers at once', function (): void {
    // Failing closed matters most exactly here, where two processes race and
    // neither can see the other's uncommitted row.
    $first = $this->customer->id;
    $second = User::factory()->fromTelegram()->create()->id;
    $sharedKey = 'cross-user-'.Str::uuid();

    $results = ForkedWorkers::run(2, function (int $index) use ($first, $second, $sharedKey): array {
        $user = User::query()->findOrFail($index === 0 ? $first : $second);

        try {
            app(WalletService::class)->credit($user, 100_000, $sharedKey, 'Cross-user credit');

            return ['ok' => true, 'outcome' => 'succeeded'];
        } catch (IdempotencyConflict) {
            return ['ok' => true, 'outcome' => 'refused'];
        }
    });

    $outcomes = array_column($results, 'outcome');

    // Exactly one credit exists, and only one customer received money.
    expect(DB::table('wallet_transactions')->where('idempotency_key', $sharedKey)->count())->toBe(1)
        ->and(array_count_values($outcomes)['succeeded'] ?? 0)->toBe(1)
        ->and((int) DB::table('users')->sum('wallet_balance_toman'))->toBe(100_000);
});

it('keeps the balance equal to the ledger through mixed contention', function (): void {
    // Credits and debits from eight processes at once. Whatever interleaving
    // occurs, the two must still agree at the end.
    $userId = $this->customer->id;
    app(WalletService::class)->credit(
        User::query()->findOrFail($userId), 100_000, (string) Str::uuid(), 'Seed',
    );

    ForkedWorkers::run(8, function (int $index) use ($userId): array {
        $user = User::query()->findOrFail($userId);
        $wallet = app(WalletService::class);

        try {
            $index % 2 === 0
                ? $wallet->credit($user, 5_000, 'mixed-credit-'.$index, 'Mixed credit')
                : $wallet->debit($user, 5_000, 'mixed-debit-'.$index, 'Mixed debit');

            return ['ok' => true];
        } catch (InsufficientBalance) {
            return ['ok' => true];
        }
    });

    $balance = (int) DB::table('users')->where('id', $userId)->value('wallet_balance_toman');
    $ledger = (int) DB::table('wallet_transactions')->where('user_id', $userId)->sum('amount_toman');

    expect($balance)->toBe($ledger)
        ->and($balance)->toBeGreaterThanOrEqual(0);

    $this->artisan('wallet:verify-integrity')->assertExitCode(0);
});
