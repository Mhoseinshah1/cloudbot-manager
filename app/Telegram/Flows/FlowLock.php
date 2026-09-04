<?php

declare(strict_types=1);

namespace App\Telegram\Flows;

use Illuminate\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockProvider;

/**
 * Keeps one customer's conversation to one worker at a time.
 *
 * The per-update lock already stops the same delivery being handled twice.
 * This is the other race: two *different* updates from one person arriving at
 * once — the confirm button double-tapped, or a tap landing while a retry of
 * the previous step is still running. Both read the same conversation state,
 * both decide it says "ready to pay", and both proceed.
 *
 * Coordination only, and short. What actually stops two orders is the purchase
 * intent's idempotency key in PostgreSQL, which holds whether or not Redis is
 * reachable; this stops the interleaving that would otherwise write one step's
 * state over another's and leave a customer's conversation describing a choice
 * they never made.
 *
 * Held for seconds, never across a provider call — nothing in a Telegram flow
 * calls a provider at all.
 */
final readonly class FlowLock
{
    /** Long enough for a flow step, short enough that a crash costs nothing. */
    private const TTL_SECONDS = 15;

    public function __construct(private CacheFactory $cache) {}

    /**
     * Take this customer's conversation lock, or return null.
     *
     * Null means somebody else has it, and the caller must hand the update back
     * to the queue rather than proceed — the state it would read is about to be
     * rewritten.
     */
    public function acquire(int $telegramUserId): ?Lock
    {
        $lock = $this->lockFor($telegramUserId);

        if (! $lock instanceof Lock) {
            return null;
        }

        return $lock->get() ? $lock : null;
    }

    public static function keyFor(int $telegramUserId): string
    {
        return 'telegram:flow:'.$telegramUserId;
    }

    /**
     * The lock, from the store that can actually make one.
     *
     * Taken from the underlying store rather than the cache repository, which
     * has no `lock()` of its own — reaching it through magic forwarding is a
     * runtime hope rather than a checked call.
     */
    private function lockFor(int $telegramUserId): ?Lock
    {
        $repository = $this->cache->store('locks');
        $store = $repository instanceof CacheRepository ? $repository->getStore() : null;

        return $store instanceof LockProvider
            ? $store->lock(self::keyFor($telegramUserId), self::TTL_SECONDS)
            : null;
    }
}
