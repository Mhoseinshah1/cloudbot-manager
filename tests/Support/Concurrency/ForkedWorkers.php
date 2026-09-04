<?php

declare(strict_types=1);

namespace Tests\Support\Concurrency;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use RuntimeException;
use Throwable;

/**
 * Runs work in genuinely separate OS processes against one PostgreSQL database.
 *
 * Nothing here simulates contention. Each child is a real process with its own
 * database connection and its own transaction, and they are released together
 * so their transactions genuinely overlap. That matters because the bugs this
 * is looking for — a lost update, two debits spending the same money — only
 * appear when two sessions are inside the database at the same moment. A
 * sequential test, however cleverly arranged, cannot produce them.
 */
final class ForkedWorkers
{
    /**
     * Drop every inherited Redis connection.
     */
    private static function purgeRedis(): void
    {
        /** @var array<string, mixed> $connections */
        $connections = config('database.redis', []);

        foreach (array_keys($connections) as $name) {
            if (in_array($name, ['client', 'options'], true)) {
                continue;
            }

            try {
                Redis::purge((string) $name);
            } catch (Throwable) {
                // Never opened in this process; nothing to drop.
            }
        }
    }

    /**
     * Run one callback per worker, concurrently, and collect what they return.
     *
     * @param  callable(int): array<string, mixed>  $work  Receives the worker index.
     * @return list<array<string, mixed>> One result per worker, in worker order.
     */
    public static function run(int $workers, callable $work): array
    {
        if (! function_exists('pcntl_fork')) {
            throw new RuntimeException('pcntl is required for concurrency tests.');
        }

        // Results come back through files: a forked child shares no memory with
        // its parent.
        $directory = sys_get_temp_dir().'/cbm-concurrency-'.bin2hex(random_bytes(6));
        mkdir($directory, 0700, true);

        // A wall-clock barrier. Every child waits for the same instant, so they
        // enter their transactions together instead of neatly one after another.
        $startAt = microtime(true) + 0.35;

        // Anything uncommitted would be invisible to the children.
        while (DB::transactionLevel() > 0) {
            DB::commit();
        }

        $pids = [];

        for ($index = 0; $index < $workers; $index++) {
            $pid = pcntl_fork();

            if ($pid === -1) {
                throw new RuntimeException('Could not fork a worker.');
            }

            if ($pid === 0) {
                // Child. Every socket open at the moment of the fork belongs to
                // the parent and must be dropped before use: two processes
                // writing down one connection corrupt its protocol. That is
                // true of the database handle and equally of Redis, which the
                // permission cache reads during an authorization check.
                DB::purge();
                self::purgeRedis();

                $result = ['worker' => $index, 'ok' => false, 'error' => null];

                try {
                    usleep((int) max(0, ($startAt - microtime(true)) * 1_000_000));

                    $result = array_merge($result, $work($index), ['worker' => $index]);
                } catch (Throwable $exception) {
                    $result['error'] = $exception::class.': '.$exception->getMessage();
                }

                file_put_contents(
                    $directory.'/'.$index.'.json',
                    json_encode($result, JSON_THROW_ON_ERROR),
                );

                // Exit hard: the child must not run the test framework's
                // shutdown, which would report a second set of results.
                exit(0);
            }

            $pids[] = $pid;
        }

        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status);
        }

        // The parent's connection was open across the fork; start clean.
        DB::purge();

        $results = [];

        for ($index = 0; $index < $workers; $index++) {
            $file = $directory.'/'.$index.'.json';

            $results[] = is_file($file)
                ? json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR)
                : ['worker' => $index, 'ok' => false, 'error' => 'worker produced no result'];

            @unlink($file);
        }

        @rmdir($directory);

        return $results;
    }
}
