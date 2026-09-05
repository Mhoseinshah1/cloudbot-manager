<?php

declare(strict_types=1);

namespace App\Servers;

use App\Models\Server;
use App\Provisioning\Exceptions\InvalidLockTopology;
use Illuminate\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Config\Repository as Config;
use Throwable;

/**
 * Keeps two workers from operating one server at the same time.
 *
 * Coordination only, exactly like the provisioning lock. What stops a duplicate
 * reboot is the action's unique idempotency key and the compare-and-set that
 * settles it; this stops the waste and the confusing interleaving of two
 * workers talking to a provider about one machine at once.
 *
 * Keyed by server rather than by action, because the thing that must not happen
 * twice at once is an operation on the machine — a power-off and a delete
 * racing on one server is exactly the sequence nobody wants to reconstruct.
 *
 * Because it is not load-bearing, failing to acquire is not an error: the
 * caller waits and the job comes back.
 */
final readonly class ServerActionLock
{
    public function __construct(
        private CacheFactory $cache,
        private Config $config,
    ) {}

    /**
     * Run the callback holding this server's lock, or return null.
     *
     * Null means somebody else holds it, and the caller must not proceed to the
     * provider.
     *
     * @template TReturn
     *
     * @param  callable(): TReturn  $work
     * @return TReturn|null
     */
    public function attempt(Server $server, callable $work): mixed
    {
        $lock = $this->lockFor($server);

        try {
            if (! $lock->get()) {
                return null;
            }
        } catch (Throwable) {
            // Redis is unreachable. A worker that cannot coordinate at all is
            // in an environment worth waiting out rather than acting alone in.
            return null;
        }

        try {
            return $work();
        } finally {
            try {
                $lock->release();
            } catch (Throwable) {
                // Expired, or Redis went away. The TTL is the backstop, and a
                // release that fails must not mask what the work did.
            }
        }
    }

    public static function keyFor(Server $server): string
    {
        return 'server:action:'.$server->getKey();
    }

    /**
     * The lock, from the store that can actually make one.
     *
     * Taken from the underlying store rather than the cache repository, which
     * has no `lock()` of its own — reaching it through magic forwarding is a
     * runtime hope rather than a checked call.
     *
     * @throws InvalidLockTopology
     */
    private function lockFor(Server $server): Lock
    {
        $repository = $this->cache->store('locks');
        $store = $repository instanceof CacheRepository ? $repository->getStore() : null;

        if (! $store instanceof LockProvider) {
            throw InvalidLockTopology::because(
                'The locks cache store cannot provide locks, so server actions cannot be coordinated.',
            );
        }

        return $store->lock(self::keyFor($server), $this->ttlSeconds());
    }

    /**
     * At least twice the provider timeout.
     *
     * A lock that expires while a call is still in flight does not do the one
     * job it has.
     */
    private function ttlSeconds(): int
    {
        $ttl = (int) $this->config->get('cloudbot.server_actions.lock_ttl_seconds', 300);
        $timeout = (int) $this->config->get('cloudbot.provisioning.provider_timeout_seconds', 120);

        return max($ttl, $timeout * 2, 1);
    }
}
