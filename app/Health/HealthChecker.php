<?php

declare(strict_types=1);

namespace App\Health;

use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Database\DatabaseManager;
use Illuminate\Redis\RedisManager;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Verifies that the application can reach the dependencies it cannot serve
 * traffic without.
 *
 * Reaching this code at all proves the application booted: the container,
 * configuration and service providers all resolved. On top of that we probe
 * PostgreSQL, which every request needs, and Redis, which carries queues,
 * bot state and locks.
 *
 * The probes are deliberately trivial (`SELECT 1`, `PING`). This runs on every
 * container health probe, so it must not become expensive, and it must never
 * touch a cloud provider API.
 *
 * Each dependency is probed independently: a failing database must not be able
 * to mask, or be masked by, the state of Redis.
 */
final readonly class HealthChecker
{
    public function __construct(
        private DatabaseManager $database,
        private RedisManager $redis,
        private Config $config,
        private LoggerInterface $logger,
    ) {}

    public function check(): HealthReport
    {
        return new HealthReport([
            'database' => $this->probe('database', $this->checkDatabase(...)),
            'redis' => $this->probe('redis', $this->checkRedis(...)),
        ]);
    }

    /**
     * `SET LOCAL` needs a transaction, which also guarantees the timeout is
     * discarded again when the probe finishes.
     */
    private function checkDatabase(): void
    {
        $connection = $this->database->connection();

        $connection->transaction(function () use ($connection): void {
            $connection->statement('SET LOCAL statement_timeout = '.($this->timeoutSeconds() * 1000));
            $connection->select('SELECT 1');
        });
    }

    /**
     * Every Redis concern is probed: a healthy cache database says nothing
     * about the separate database holding queued jobs.
     */
    private function checkRedis(): void
    {
        foreach (['cache', 'queue', 'state', 'locks'] as $connection) {
            $this->redis->connection($connection)->command('ping', []);
        }
    }

    private function probe(string $service, callable $probe): bool
    {
        try {
            $probe();

            return true;
        } catch (Throwable $exception) {
            // Detail goes to the log, never to the HTTP response.
            $this->logger->warning('Health probe failed.', [
                'service' => $service,
                'exception' => $exception::class,
            ]);

            return false;
        }
    }

    private function timeoutSeconds(): int
    {
        $timeout = $this->config->get('cloudbot.health.timeout_seconds', 2);

        return is_numeric($timeout) ? max(1, (int) $timeout) : 2;
    }
}
