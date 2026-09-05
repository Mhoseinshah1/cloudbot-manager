<?php

declare(strict_types=1);

namespace App\Pricing;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Coordinates pricing a new sale against recording a new applicable FX rate.
 *
 * The one authority a row lock cannot protect. Every other control a sale
 * depends on is a row that gets updated — the kill switch, the terms, the
 * catalog line, the image — so a share lock on it makes an administrator's
 * write wait. An exchange rate is not updated; it is appended. `currentRate()`
 * answers with the newest applicable row, so a new row *becomes* the authority
 * the instant it commits, and no lock on the previous row can hold back an
 * INSERT of the next one.
 *
 * That leaves a window nothing else closes:
 *
 *   1. a purchase transaction reads rate A;
 *   2. another session inserts and commits applicable rate B;
 *   3. the purchase inserts its order, priced at A;
 *   4. the purchase commits, after B was already the current rate.
 *
 * The order is now committed under financial authority that had already
 * changed. Serializable isolation does not fix this and is worth saying plainly:
 * the history above *is* serializable — "the purchase happened, then the rate
 * changed" is a legal serial order — so PostgreSQL correctly does not abort it.
 * What is wanted is stronger than serializability, and it has to be asked for.
 *
 * So the two operations take a readers/writer lock on the currency. Sales hold
 * it shared, and never wait on each other. Recording a rate takes it
 * exclusively, and waits for whatever sales are mid-flight — briefly, because a
 * purchase transaction does no network work.
 *
 * Transaction-scoped (`pg_advisory_xact_*`) rather than session-scoped, so the
 * lock is released by commit or rollback and cannot leak past an exception into
 * a pooled connection that then blocks every later sale.
 */
final readonly class FxAuthorityLock
{
    /**
     * The namespace half of the advisory key.
     *
     * PostgreSQL advisory locks share one global space across everything
     * connected to the database, so a bare hash risks colliding with an
     * unrelated lock somebody adds later. The two-integer form gives an
     * explicit namespace, and this is ours: ASCII "CBMF", CloudBot Manager FX.
     */
    private const NAMESPACE_KEY = 0x43424D46;

    /** Hold the currency's authority against changes, without blocking sales. */
    public function shared(string $currency): void
    {
        $this->assertInTransaction();

        DB::select('SELECT pg_advisory_xact_lock_shared(?, ?)', [self::NAMESPACE_KEY, self::keyFor($currency)]);
    }

    /** Take the currency's authority, waiting for any sale still pricing against it. */
    public function exclusive(string $currency): void
    {
        $this->assertInTransaction();

        DB::select('SELECT pg_advisory_xact_lock(?, ?)', [self::NAMESPACE_KEY, self::keyFor($currency)]);
    }

    /**
     * The lock key for one currency.
     *
     * Built from the three characters rather than hashed, which for a fixed
     * three-letter domain is both collision-free by construction and obvious to
     * a reader — a hash would be neither, and would need a comment explaining
     * why its collisions do not matter.
     */
    public static function keyFor(string $currency): int
    {
        $code = strtoupper(trim($currency));

        if (preg_match('/^[A-Z]{3}$/', $code) !== 1) {
            // A currency this system does not recognise must not silently share
            // a lock with one it does.
            throw new InvalidArgumentException('An FX authority lock needs a three-letter currency code.');
        }

        return (ord($code[0]) << 16) | (ord($code[1]) << 8) | ord($code[2]);
    }

    /**
     * A transaction-scoped lock outside a transaction is a lock nobody holds.
     *
     * It would be taken and released by the same implicit statement, protecting
     * exactly nothing while reading like protection — the worst failure mode
     * available here, because it is silent.
     */
    private function assertInTransaction(): void
    {
        if (DB::transactionLevel() < 1) {
            throw new \LogicException(
                'An FX authority lock must be taken inside a transaction, or it is released immediately.',
            );
        }
    }
}
