<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;

/**
 * The migrations that RefreshDatabase just ran are the real ones, executed
 * against PostgreSQL.
 */
it('creates the sessions table', function (): void {
    expect(Schema::hasTable('sessions'))->toBeTrue()
        ->and(Schema::hasColumns('sessions', ['id', 'payload', 'last_activity']))->toBeTrue();
});

it('creates the failed jobs table', function (): void {
    expect(Schema::hasTable('failed_jobs'))->toBeTrue()
        ->and(Schema::hasColumns('failed_jobs', ['uuid', 'connection', 'queue', 'payload', 'exception']))->toBeTrue();
});

it('ships no domain tables yet', function (): void {
    // Phase 1 is foundation only. Identity, wallet, orders and provisioning
    // arrive in their own phases; finding them here means scope crept.
    foreach (['users', 'wallet_transactions', 'orders', 'servers', 'subscriptions'] as $table) {
        expect(Schema::hasTable($table))->toBeFalse();
    }
});
