<?php

declare(strict_types=1);

namespace Tests\Support\Provisioning;

use Illuminate\Support\Facades\DB;
use PDO;

/**
 * Reads the database from outside the application's own connection.
 *
 * The point of a second connection is that it cannot see uncommitted work.
 * Asking the application's own handle whether an order is `provisioning` proves
 * nothing at all — a transaction sees its own writes, so the answer would be
 * yes whether or not anything had been committed, which is exactly the bug this
 * is meant to catch.
 *
 * A separate PDO session in READ COMMITTED sees only what has actually landed.
 * If it can read the token, the token is durable, and a worker dying at that
 * instant would leave the record behind.
 */
final class CommitProbe
{
    private function __construct(private readonly PDO $pdo) {}

    public static function open(): self
    {
        /** @var array<string, mixed> $config */
        $config = DB::connection()->getConfig();

        $dsn = sprintf(
            'pgsql:host=%s;port=%s;dbname=%s',
            (string) ($config['host'] ?? '127.0.0.1'),
            (string) ($config['port'] ?? 5432),
            (string) ($config['database'] ?? ''),
        );

        $pdo = new PDO(
            $dsn,
            (string) ($config['username'] ?? ''),
            (string) ($config['password'] ?? ''),
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                // No emulation: the probe must behave like a real client
                // session, not like a statement replayed on ours.
                PDO::ATTR_EMULATE_PREPARES => false,
            ],
        );

        return new self($pdo);
    }

    /**
     * What another session can see of this order, right now.
     *
     * @return array{status: string|null, provisioning_uuid: string|null}|null
     */
    public function readOrder(int $orderId): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT status, provisioning_uuid FROM orders WHERE id = :id'
        );
        $statement->execute(['id' => $orderId]);

        /** @var array<string, mixed>|false $row */
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        if ($row === false) {
            return null;
        }

        return [
            'status' => isset($row['status']) ? (string) $row['status'] : null,
            'provisioning_uuid' => isset($row['provisioning_uuid'])
                ? (string) $row['provisioning_uuid']
                : null,
        ];
    }

    public function close(): void
    {
        // PDO closes with the object; the explicit call documents intent at the
        // call site so a probe is not left open across a forked test.
    }
}
