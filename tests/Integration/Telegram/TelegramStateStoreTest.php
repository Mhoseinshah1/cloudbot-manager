<?php

declare(strict_types=1);

use App\Telegram\Exceptions\UnsafeConversationState;
use App\Telegram\MainMenu;
use App\Telegram\TelegramStateStore;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;

/**
 * Where a half-finished conversation is remembered.
 *
 * Two properties matter and neither is a configuration assertion. State must
 * survive `cache:clear`, which issues FLUSHDB and ignores key prefixes — so it
 * is flushed for real here and checked afterwards. And it must refuse to hold
 * anything that should not outlive the moment.
 */
beforeEach(function (): void {
    foreach (['cache', 'queue', 'state', 'locks'] as $connection) {
        Redis::connection($connection)->flushdb();
    }

    $this->state = app(TelegramStateStore::class);
    $this->customer = 5_500_123_456;
});

it('remembers, returns and forgets a conversation', function (): void {
    expect($this->state->get($this->customer))->toBeNull()
        ->and($this->state->has($this->customer))->toBeFalse();

    $this->state->put($this->customer, ['flow' => 'buy_server', 'step' => 2, 'confirmed' => false]);

    expect($this->state->get($this->customer))
        ->toBe(['flow' => 'buy_server', 'step' => 2, 'confirmed' => false])
        ->and($this->state->has($this->customer))->toBeTrue();

    $this->state->forget($this->customer);

    expect($this->state->get($this->customer))->toBeNull();
});

it('keys state by identity, never by a display name', function (): void {
    // A username can be released and taken by somebody else; the numeric id
    // cannot.
    expect($this->state->key($this->customer))->toBe("telegram:conversation:{$this->customer}");
});

it('keeps one conversation out of another', function (): void {
    $this->state->put($this->customer, ['flow' => 'buy_server']);
    $this->state->put(6_600_987_654, ['flow' => 'wallet']);

    expect($this->state->get($this->customer))->toBe(['flow' => 'buy_server'])
        ->and($this->state->get(6_600_987_654))->toBe(['flow' => 'wallet']);

    $this->state->forget($this->customer);

    // Forgetting one leaves the other alone.
    expect($this->state->get($this->customer))->toBeNull()
        ->and($this->state->get(6_600_987_654))->toBe(['flow' => 'wallet']);
});

it('lives in the dedicated state database', function (): void {
    $this->state->put($this->customer, ['flow' => 'buy_server']);

    $key = $this->state->key($this->customer);

    expect(Redis::connection('state')->exists($key))->toBe(1)
        // And in none of the others.
        ->and(Redis::connection('cache')->exists($key))->toBe(0)
        ->and(Redis::connection('queue')->exists($key))->toBe(0)
        ->and(Redis::connection('locks')->exists($key))->toBe(0);
});

it('survives a cache flush', function (): void {
    // Not a configuration assertion: `cache:clear` issues FLUSHDB, so the only
    // real protection is living in a different database. A customer must not
    // lose a half-finished purchase to a routine operational command.
    Cache::put('something', 'cached', 60);
    $this->state->put($this->customer, ['flow' => 'buy_server', 'step' => 3]);

    Artisan::call('cache:clear');

    expect(Cache::get('something'))->toBeNull()
        ->and($this->state->get($this->customer))->toBe(['flow' => 'buy_server', 'step' => 3]);
});

it('does not disturb queued work or provisioning locks', function (): void {
    Redis::connection('queue')->set('a-job', 'payload');
    Redis::connection('locks')->set('provisioning:order:1', 'held');

    $this->state->put($this->customer, ['flow' => 'buy_server']);
    $this->state->forget($this->customer);

    expect(Redis::connection('queue')->get('a-job'))->toBe('payload')
        ->and(Redis::connection('locks')->get('provisioning:order:1'))->toBe('held');
});

it('expires on the configured ttl', function (): void {
    config()->set('telegram.state.ttl_seconds', 900);

    app(TelegramStateStore::class)->put($this->customer, ['flow' => 'buy_server']);

    $remaining = app(TelegramStateStore::class)->secondsRemaining($this->customer);

    expect($remaining)->toBeLessThanOrEqual(900)
        ->and($remaining)->toBeGreaterThan(880);
});

it('is written with an expiry, never without one', function (): void {
    $this->state->put($this->customer, ['flow' => 'buy_server']);

    // -1 is Redis for "no expiry". State that never expires is a conversation
    // resumed a year later.
    expect(Redis::connection('state')->ttl($this->state->key($this->customer)))
        ->toBeGreaterThan(0);
});

it('forgets a conversation once its time is up', function (): void {
    config()->set('telegram.state.ttl_seconds', 1);
    $store = app(TelegramStateStore::class);

    $store->put($this->customer, ['flow' => 'buy_server', 'step' => 2]);

    expect($store->get($this->customer))->not->toBeNull();

    // Expired at the source rather than by waiting: the assertion is about what
    // a caller sees afterwards, not about Redis's clock.
    Redis::connection('state')->del($store->key($this->customer));

    expect($store->get($this->customer))->toBeNull()
        ->and($store->secondsRemaining($this->customer))->toBeNull();
});

it('offers a customer the menu rather than guessing at lost choices', function (): void {
    // What an expired conversation is for: there is nothing to resume, and a
    // half-remembered purchase must never be completed from the parts that
    // survived.
    expect($this->state->get($this->customer))->toBeNull()
        ->and(MainMenu::STATE_EXPIRED)->toContain('منوی اصلی');
});

it('refuses to hold a credential, whatever it is called', function (string $key): void {
    expect(fn () => $this->state->put($this->customer, [$key => 'value']))
        ->toThrow(UnsafeConversationState::class);

    expect($this->state->get($this->customer))->toBeNull();
})->with(['password', 'root_password', 'api_token', 'webhook_secret', 'authorization', 'private_key']);

it('refuses a value that looks like a credential', function (): void {
    $token = '77'.random_int(10_000_000, 99_999_999).':AA'.bin2hex(random_bytes(16));

    expect(fn () => $this->state->put($this->customer, ['note' => "bot{$token}"]))
        ->toThrow(UnsafeConversationState::class);
});

it('refuses anything that is not a simple value', function (mixed $value): void {
    expect(fn () => $this->state->put($this->customer, ['thing' => $value]))
        ->toThrow(UnsafeConversationState::class);
})->with([
    'a model' => fn () => new App\Models\User,
    'nested data' => [['a' => ['b' => 'c']]],
    'an object' => fn () => new stdClass,
]);

it('stores json, never php serialization', function (): void {
    $this->state->put($this->customer, ['flow' => 'buy_server', 'step' => 1]);

    /** @var string $raw */
    $raw = Redis::connection('state')->get($this->state->key($this->customer));

    // A serialized object is code that runs on the way back in, and this data
    // is one Redis mistake away from being attacker-controlled.
    expect($raw)->toStartWith('{')
        ->and($raw)->not->toContain('O:')
        ->and(json_decode($raw, true))->toMatchArray(['v' => 1]);
});

it('treats unreadable state as no state', function (): void {
    Redis::connection('state')->set($this->state->key($this->customer), 'not json at all');

    // Never a reason to fail a customer's interaction: they simply start again.
    expect($this->state->get($this->customer))->toBeNull();
});

it('ignores state written in a shape it does not recognise', function (): void {
    Redis::connection('state')->set(
        $this->state->key($this->customer),
        json_encode(['v' => 99, 'data' => ['flow' => 'from_the_future']], JSON_THROW_ON_ERROR),
    );

    expect($this->state->get($this->customer))->toBeNull();
});

it('keeps unicode intact', function (): void {
    $this->state->put($this->customer, ['label' => 'خرید سرور']);

    expect($this->state->get($this->customer))->toBe(['label' => 'خرید سرور']);
});
