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
    ] as $table) {
        expect(Schema::hasTable($table))->toBeTrue("expected {$table}");
    }
});

it('ships no table belonging to a later phase', function (): void {
    // Guards against scope creep. Providers, money, orders, provisioning and
    // Telegram update handling each arrive in their own phase; finding one of
    // these here means work was pulled forward.
    $laterPhases = [
        'products', 'product_location_prices', 'exchange_rates',
        'wallet_transactions', 'payments', 'invoices',
        'orders', 'provisioning_attempts', 'servers', 'server_actions', 'subscriptions',
        'telegram_updates', 'outbox_messages', 'notification_logs',
    ];

    foreach ($laterPhases as $table) {
        expect(Schema::hasTable($table))->toBeFalse("{$table} belongs to a later phase");
    }
});
