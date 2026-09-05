<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Tests\Fixtures\NoopJob;

/**
 * The specification requires that `cache:clear` cannot destroy queued jobs,
 * Telegram conversation state or provisioning locks.
 *
 * This is not a configuration assertion: `cache:clear` issues FLUSHDB, which
 * wipes an entire Redis database and ignores key prefixes. The only real
 * protection is that each concern lives in its own database, so these tests
 * flush the cache for real and check what survived.
 */
beforeEach(function (): void {
    foreach (['cache', 'queue', 'state', 'locks'] as $connection) {
        Redis::connection($connection)->flushdb();
    }
});

it('gives each concern its own redis database', function (): void {
    $databases = [];

    foreach (['cache', 'queue', 'state', 'locks'] as $connection) {
        $databases[$connection] = config("database.redis.{$connection}.database");
    }

    expect(array_unique($databases))->toHaveCount(4);
});

it('keeps queue, state and lock data when the cache is cleared', function (): void {
    Cache::put('cache-entry', 'cached', 60);
    Redis::connection('queue')->set('queued-job', 'payload');
    Redis::connection('state')->set('conversation', 'awaiting-location');
    Redis::connection('locks')->set('provisioning-lock', 'held');

    Artisan::call('cache:clear');

    // The cache is genuinely gone...
    expect(Cache::get('cache-entry'))->toBeNull();

    // ...and nothing else was collateral damage.
    expect(Redis::connection('queue')->get('queued-job'))->toBe('payload')
        ->and(Redis::connection('state')->get('conversation'))->toBe('awaiting-location')
        ->and(Redis::connection('locks')->get('provisioning-lock'))->toBe('held');
});

it('keeps queued jobs when the cache is cleared', function (): void {
    // The same guarantee, expressed through the queue API rather than raw keys.
    Queue::push(new NoopJob);

    expect(Queue::size('default'))->toBe(1);

    Artisan::call('cache:clear');

    expect(Queue::size('default'))->toBe(1);
});

it('keeps a held cache lock when the cache is cleared', function (): void {
    // Locks are taken through the cache API but must live in the lock database.
    $lock = Cache::store('locks')->lock('provisioning:order:1', 60);

    expect($lock->get())->toBeTrue();

    Artisan::call('cache:clear');

    // A second holder still cannot take it: the lock survived the flush.
    expect(Cache::store('locks')->lock('provisioning:order:1', 60)->get())->toBeFalse();

    $lock->release();
});

it('writes queued jobs to the queue database, not the cache database', function (): void {
    Queue::push(new NoopJob);

    expect(Redis::connection('queue')->keys('*'))->not->toBeEmpty()
        ->and(Redis::connection('cache')->keys('*'))->toBeEmpty();
});

it('does not put cache entries in the queue, state or lock databases', function (): void {
    Cache::put('cache-entry', 'cached', 60);

    expect(Redis::connection('cache')->keys('*'))->not->toBeEmpty()
        ->and(Redis::connection('queue')->keys('*'))->toBeEmpty()
        ->and(Redis::connection('state')->keys('*'))->toBeEmpty()
        ->and(Redis::connection('locks')->keys('*'))->toBeEmpty();
});

it('points the default redis connection at the flushable cache database', function (): void {
    // An accidental Redis::connection() call must land where a flush is safe.
    expect(config('database.redis.default.database'))
        ->toBe(config('database.redis.cache.database'));
});
