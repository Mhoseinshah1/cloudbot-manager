<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Payments and invoices are retained, not erasable.
 *
 * The ledger and the audit log are append-only, so their whole history is
 * already safe. Payments and invoices are different: their rows legitimately
 * change — a payment is verified or rejected, an invoice's status moves on —
 * so they cannot be frozen the same way. What they must not do is disappear.
 * A payment row is the record of money a customer sent; an invoice is the
 * record of what they were charged. Both are asked about long after they stop
 * being operationally interesting, and neither can be reconstructed.
 *
 * The models refuse deletion too. This is the guard that still holds for a
 * query builder call, a future model that never learned the rule, or someone
 * at a psql prompt — which is the case that matters, because that is where an
 * accidental `DELETE` with a wrong WHERE clause actually happens.
 *
 * UPDATE is deliberately untouched. TRUNCATE is not a row-level DELETE and
 * does not fire these triggers, which is what lets the concurrency suite reset
 * its own tables between runs.
 */
return new class extends Migration
{
    /**
     * @var list<string>
     */
    private const TABLES = ['payments', 'invoices'];

    public function up(): void
    {
        // One function, shared. Two copies of the same rule would be two things
        // to keep in step, and the one that fell behind would be the one that
        // let a row through.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION financial_records_reject_delete() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION '% is retained financial history: DELETE is not permitted', TG_TABLE_NAME
                    USING ERRCODE = 'restrict_violation';
            END;
            $$ LANGUAGE plpgsql;
        SQL);

        foreach (self::TABLES as $table) {
            DB::unprepared(<<<SQL
                CREATE TRIGGER {$table}_no_delete
                    BEFORE DELETE ON {$table}
                    FOR EACH ROW EXECUTE FUNCTION financial_records_reject_delete();
            SQL);
        }
    }

    public function down(): void
    {
        // Triggers first: dropping the function while one still references it
        // would fail, and a half-rolled-back migration is worse than none.
        foreach (self::TABLES as $table) {
            if (Schema::hasTable($table)) {
                DB::unprepared("DROP TRIGGER IF EXISTS {$table}_no_delete ON {$table}");
            }
        }

        DB::unprepared('DROP FUNCTION IF EXISTS financial_records_reject_delete()');
    }
};
