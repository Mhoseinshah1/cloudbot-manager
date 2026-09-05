<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Proves the test suite runs against a real PostgreSQL 16 server.
 *
 * SQLite must never stand in here: this system's correctness rests on
 * PostgreSQL behaviour (row locking for wallet mutations, BIGINT money
 * columns), and SQLite would silently accept code that PostgreSQL rejects.
 */
it('runs against postgresql', function (): void {
    expect(DB::connection()->getDriverName())->toBe('pgsql');
});

it('runs against postgresql 16 or newer', function (): void {
    $version = (int) DB::scalar('SHOW server_version_num');

    expect($version)->toBeGreaterThanOrEqual(160000);
});

it('stores bigint values beyond the 32-bit range', function (): void {
    // Customer money is BIGINT Toman. A 32-bit column would overflow at
    // ~2.1 billion Toman, which is an ordinary balance in this currency.
    Schema::create('bigint_probe', function ($table): void {
        $table->id();
        $table->bigInteger('amount');
    });

    $amount = 9223372036854775807;
    DB::table('bigint_probe')->insert(['amount' => $amount]);

    expect((int) DB::table('bigint_probe')->value('amount'))->toBe($amount);

    Schema::drop('bigint_probe');
});

it('supports select for update inside a transaction', function (): void {
    // The wallet depends on SELECT ... FOR UPDATE. Prove the driver and server
    // accept it before any money code is written against it.
    Schema::create('lock_probe', function ($table): void {
        $table->id();
        $table->bigInteger('balance');
    });

    DB::table('lock_probe')->insert(['id' => 1, 'balance' => 100]);

    $locked = DB::transaction(fn () => DB::table('lock_probe')->lockForUpdate()->find(1));

    expect((int) $locked->balance)->toBe(100);

    Schema::drop('lock_probe');
});

it('rolls back a failed transaction', function (): void {
    Schema::create('rollback_probe', function ($table): void {
        $table->id();
    });

    try {
        DB::transaction(function (): void {
            DB::table('rollback_probe')->insert(['id' => 1]);

            throw new RuntimeException('forced failure');
        });
    } catch (RuntimeException) {
        // expected
    }

    expect(DB::table('rollback_probe')->count())->toBe(0);

    Schema::drop('rollback_probe');
});
