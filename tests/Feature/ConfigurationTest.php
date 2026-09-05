<?php

declare(strict_types=1);

it('calculates and stores time in utc', function (): void {
    // Every stored timestamp is UTC. Customer-facing rendering converts on the
    // way out; the database never holds local time.
    expect(config('app.timezone'))->toBe('UTC')
        ->and(date_default_timezone_get())->toBe('UTC');
});

it('renders customer-facing output in tehran time', function (): void {
    expect(config('cloudbot.customer_timezone'))->toBe('Asia/Tehran');
});

it('uses postgresql', function (): void {
    expect(config('database.default'))->toBe('pgsql');
});

it('pins the test suite to postgresql', function (): void {
    // Laravel merges its own default connections into config, so SQLite is
    // still defined by the framework. What matters is that nothing can run on
    // it by accident: the app default and the test harness both name pgsql,
    // and the integration suite asserts the live driver.
    $phpunit = (string) file_get_contents(base_path('phpunit.xml'));

    expect($phpunit)->toContain('<env name="DB_CONNECTION" value="pgsql"/>')
        ->and($phpunit)->not->toContain('value="sqlite"')
        ->and(config('database.connections.pgsql.driver'))->toBe('pgsql');
});

it('takes locks from the lock database, not the cache database', function (): void {
    expect(config('cache.stores.redis.lock_connection'))->toBe('locks')
        ->and(config('cache.stores.locks.connection'))->toBe('locks');
});

it('queues onto the queue database', function (): void {
    expect(config('queue.connections.redis.connection'))->toBe('queue');
});

it('stores failed jobs durably in postgresql', function (): void {
    // A job that exhausted its retries must outlive Redis.
    expect(config('queue.failed.driver'))->toBe('database-uuids')
        ->and(config('queue.failed.database'))->toBe('pgsql');
});

it('keeps sessions out of redis', function (): void {
    // The cache database is flushed by cache:clear; the others are reserved.
    expect(config('session.driver'))->toBe('database');
});

it('defaults to debug off', function (): void {
    $example = file_get_contents(base_path('.env.example'));

    expect($example)->toContain('APP_DEBUG=false')
        ->and($example)->not->toContain('APP_DEBUG=true');
});

it('logs as structured json to stderr', function (): void {
    expect(config('logging.default'))->toBe('stderr')
        ->and(config('logging.channels.stderr.formatter'))->toBe(Monolog\Formatter\JsonFormatter::class);
});

it('redacts secrets on every channel that writes output', function (): void {
    foreach (['stderr', 'single'] as $channel) {
        expect(config("logging.channels.{$channel}.tap"))
            ->toContain(App\Logging\RedactSecrets::class);
    }
});
