<?php

declare(strict_types=1);

namespace App\Telegram;

use App\Support\Secrets\SecretScrubber;
use App\Telegram\Exceptions\UnsafeConversationState;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Illuminate\Redis\Connections\Connection;
use JsonException;

/**
 * Where a half-finished conversation is remembered.
 *
 * Ephemeral by design. A customer part-way through choosing a server has made
 * choices worth holding for a while and worth forgetting afterwards, which is
 * neither what PostgreSQL is for nor what the cache is for — `cache:clear`
 * issues FLUSHDB and ignores key prefixes, so state sharing that database would
 * be wiped by a routine operational command mid-purchase.
 *
 * So it lives in the dedicated `state` Redis database, alongside nothing else.
 *
 * Two rules about content. It is JSON, never PHP serialization: a serialized
 * object is code that runs on the way back in, and this data is one Redis
 * mistake away from being attacker-controlled. And it is scalars only — an
 * Eloquent model written here would be a stale copy of a row that has since
 * changed, which is how a customer is charged yesterday's price.
 *
 * Keyed by `telegram_user_id`, never by username. A username can be released
 * and taken by somebody else; the numeric id cannot.
 */
final readonly class TelegramStateStore
{
    /** The shape marker, so a later format change can recognise old state. */
    private const VERSION = 1;

    public function __construct(
        private RedisFactory $redis,
        private Config $config,
    ) {}

    /**
     * Remember this conversation's state, replacing whatever was there.
     *
     * @param  array<string, scalar|null>  $state
     *
     * @throws UnsafeConversationState
     */
    public function put(int $telegramUserId, array $state): void
    {
        $this->assertSafe($state);

        $encoded = json_encode(
            ['v' => self::VERSION, 'data' => $state],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE,
        );

        // Set with an expiry in one command, so state can never be written
        // without one and outlive the conversation it belongs to.
        $this->connection()->setex($this->key($telegramUserId), $this->ttlSeconds(), $encoded);
    }

    /**
     * What this conversation was doing, or null.
     *
     * Null means expired, never started, or written in a shape this version
     * does not recognise. All three are the same thing to a caller: there is
     * nothing to resume, and the customer goes back to the menu rather than
     * having their missing choices guessed at.
     *
     * @return array<string, scalar|null>|null
     */
    public function get(int $telegramUserId): ?array
    {
        /** @var mixed $raw */
        $raw = $this->connection()->get($this->key($telegramUserId));

        if (! is_string($raw) || $raw === '') {
            return null;
        }

        try {
            /** @var mixed $decoded */
            $decoded = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            // Unreadable state is no state. Never a reason to fail a customer's
            // interaction.
            return null;
        }

        if (! is_array($decoded) || ($decoded['v'] ?? null) !== self::VERSION) {
            return null;
        }

        $data = $decoded['data'] ?? null;

        if (! is_array($data)) {
            return null;
        }

        /** @var array<string, scalar|null> $data */
        return $data;
    }

    public function forget(int $telegramUserId): void
    {
        $this->connection()->del($this->key($telegramUserId));
    }

    public function has(int $telegramUserId): bool
    {
        return $this->get($telegramUserId) !== null;
    }

    /** How many seconds the current state has left, or null if there is none. */
    public function secondsRemaining(int $telegramUserId): ?int
    {
        /** @var mixed $ttl */
        $ttl = $this->connection()->ttl($this->key($telegramUserId));

        return is_int($ttl) && $ttl >= 0 ? $ttl : null;
    }

    /**
     * Stable, and derived from identity rather than from a display name.
     *
     * The connection supplies its own `state:` prefix, so this is the whole
     * key within that database.
     */
    public function key(int $telegramUserId): string
    {
        return "telegram:conversation:{$telegramUserId}";
    }

    public function ttlSeconds(): int
    {
        $ttl = (int) $this->config->get('telegram.state.ttl_seconds', 1800);

        // A non-positive TTL would mean state that never expires, or a `setex`
        // Redis refuses outright.
        return max(1, $ttl);
    }

    /**
     * Refuse anything that should not be remembered.
     *
     * Two separate refusals. A key that names a secret is refused whatever its
     * value, because the name alone says somebody intended to store one here;
     * and a non-scalar value is refused because it is either a nested blob or
     * an object, and neither belongs in a conversation's notes.
     *
     * @param  array<array-key, mixed>  $state
     *
     * @throws UnsafeConversationState
     */
    private function assertSafe(array $state): void
    {
        foreach ($state as $key => $value) {
            if (! is_string($key)) {
                throw UnsafeConversationState::becauseKeyIsNotNamed();
            }

            if (SecretScrubber::isSecretKey($key)) {
                throw UnsafeConversationState::becauseKeyNamesASecret($key);
            }

            if ($value !== null && ! is_scalar($value)) {
                throw UnsafeConversationState::becauseValueIsNotScalar($key);
            }

            if (is_string($value) && SecretScrubber::scrubText($value) !== $value) {
                // The value itself looks like a credential, whatever it is
                // called.
                throw UnsafeConversationState::becauseValueLooksLikeASecret($key);
            }
        }
    }

    private function connection(): Connection
    {
        $name = (string) $this->config->get('telegram.state.connection', 'state');

        return $this->redis->connection($name);
    }
}
