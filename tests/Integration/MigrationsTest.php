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

it('creates the identity and administration tables', function (): void {
    foreach ([
        'users', 'telegram_accounts', 'settings', 'audit_logs', 'roles', 'permissions',
        'providers', 'provider_credentials', 'provider_locations', 'provider_plans', 'provider_images',
        'fake_provider_servers', 'fake_provider_actions',
        'wallet_transactions', 'payments', 'invoices',
        'products', 'product_location_prices', 'exchange_rates',
        'orders', 'outbox_messages',
        'provisioning_attempts', 'servers', 'subscriptions',
        'telegram_updates',
    ] as $table) {
        expect(Schema::hasTable($table))->toBeTrue("expected {$table}");
    }
});

it('ships no table belonging to a later phase', function (): void {
    // Guards against scope creep. The boundary moves forward as each phase
    // lands: Telegram update handling arrived with Phase 8, so what is now
    // premature is customer server actions, notification history and Release
    // 1.1 billing.
    $laterPhases = [
        'server_actions', 'notification_logs', 'billing_charges',
    ];

    foreach ($laterPhases as $table) {
        expect(Schema::hasTable($table))->toBeFalse("{$table} belongs to a later phase");
    }
});
