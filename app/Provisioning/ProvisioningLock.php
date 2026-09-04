<?php

declare(strict_types=1);

namespace App\Provisioning;

use App\Models\Order;
use App\Provisioning\Exceptions\InvalidLockTopology;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Config\Repository as Config;
use Throwable;

/**
 * Keeps two workers from calling one provider about one order at the same time.
 *
 * Coordination only. Nothing here prevents a duplicate server — that is the
 * durable provisioning token, the provider's own idempotency contract and the
 * unique constraints in PostgreSQL, all of which hold whether or not Redis is
 * reachable. This just stops the obvious waste of two workers racing, and stops
 * the confusing interleaving that makes an incident hard to read afterwards.
 *
 * Because it is not load-bearing, failing to acquire is not an error: the
 * caller simply does not call the provider and lets the job come back. A lock
 * that cannot be taken is a reason to wait, never a reason to guess.
 *
 * Held in the dedicated locks Redis database, which `cache:clear` does not
 * touch — a routine cache flush must not release a lock covering a provider
 * call that is still in flight.
 */
final readonly class ProvisioningLock
{
    public function __construct(
        private CacheFactory $cache,
        private Config $config,
    ) {}

    /**
     * Run the callback while holding this order's lock, or return null.
     *
     * Null means somebody else holds it. The caller must treat that as "not
     * now" and must not proceed to the provider.
     *
     * @template TReturn
     *
     * @param  callable(): TReturn  $work
     * @return TReturn|null
     */
    public function attempt(Order $order, callable $work): mixed
    {
        $lock = $this->lockFor($order);

        try {
            if (! $lock->get()) {
                return null;
            }
        } catch (Throwable) {
            // Redis is unreachable. Refuse rather than proceed: the lock is not
            // what makes this safe, but a worker that cannot coordinate at all
            // is in an environment worth waiting out.
            return null;
        }

        try {
            return $work();
        } finally {
            try {
                $lock->release();
            } catch (Throwable) {
                // Already expired, or Redis went away mid-call. The TTL is the
                // backstop; failing to release must not mask the real outcome.
            }
        }
    }

    /** Whether this order's lock is currently held by somebody. */
    public function isHeld(Order $order): bool
    {
        $lock = $this->lockFor($order);

        if (! $lock->get()) {
            return true;
        }

        $lock->release();

        return false;
    }

    /**
     * Deterministic, per order.
     *
     * Per order rather than per attempt or per worker: the thing that must not
     * happen twice at once is work on one customer's purchase.
     */
    public static function keyFor(Order $order): string
    {
        return 'provisioning:order:'.$order->getKey();
    }

    /**
     * How long the lock is held, in seconds.
     *
     * @throws InvalidLockTopology when the deployment is configured such that
     *                             the lock could expire mid-call.
     */
    public function ttlSeconds(): int
    {
        $ttl = (int) $this->config->get('cloudbot.provisioning.lock_ttl_seconds');
        $timeout = (int) $this->config->get('cloudbot.provisioning.provider_timeout_seconds');

        self::assertTopology($ttl, $timeout);

        return $ttl;
    }

    /**
     * The specification's rule, stated once.
     *
     * A lock shorter than two provider timeouts can expire while the call it
     * covers is still running, at which point a second worker takes it and
     * believes it is alone. Refused at the boundary rather than tolerated,
     * because the failure is silent and only shows up as a duplicate machine.
     *
     * @throws InvalidLockTopology
     */
    public static function assertTopology(int $lockTtlSeconds, int $providerTimeoutSeconds): void
    {
        if ($providerTimeoutSeconds <= 0) {
            throw InvalidLockTopology::because('A provider operation timeout must be a positive number of seconds.');
        }

        if ($lockTtlSeconds < 2 * $providerTimeoutSeconds) {
            throw InvalidLockTopology::because(
                "A provisioning lock TTL of {$lockTtlSeconds}s is shorter than twice the "
                ."{$providerTimeoutSeconds}s provider timeout, so it can expire while a call is still in flight.",
            );
        }
    }

    private function lockFor(Order $order): Lock
    {
        return $this->cache->store('locks')->lock(self::keyFor($order), $this->ttlSeconds());
    }
}
