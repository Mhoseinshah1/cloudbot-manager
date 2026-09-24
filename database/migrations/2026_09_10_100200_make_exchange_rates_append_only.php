<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Exchange rates are history, and history is append-only.
 *
 * Every order and every invoice carries the rate it was priced against, and
 * `currentRate()` answers with the newest applicable row. Rewriting an old row
 * therefore does not "correct" a rate — it silently restates what a customer
 * was charged against, and no reader can tell. A new rate is a new row; that is
 * the only way one changes.
 *
 * The application already treats the table this way. That was the whole gap:
 * discipline in PHP is not a control when psql, a migration, an admin tool or a
 * future code path can still issue UPDATE. Same pattern as `audit_logs` and the
 * financial-record delete guard — raising inside a BEFORE trigger aborts the
 * statement, so the write fails loudly rather than silently affecting no rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION exchange_rates_reject_mutation() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'exchange_rates is append-only: % is not permitted, record a new rate instead', TG_OP
                    USING ERRCODE = 'restrict_violation';
            END;
            $$ LANGUAGE plpgsql;

            DROP TRIGGER IF EXISTS exchange_rates_no_update ON exchange_rates;
            DROP TRIGGER IF EXISTS exchange_rates_no_delete ON exchange_rates;

            CREATE TRIGGER exchange_rates_no_update
                BEFORE UPDATE ON exchange_rates
                FOR EACH ROW EXECUTE FUNCTION exchange_rates_reject_mutation();

            CREATE TRIGGER exchange_rates_no_delete
                BEFORE DELETE ON exchange_rates
                FOR EACH ROW EXECUTE FUNCTION exchange_rates_reject_mutation();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS exchange_rates_no_update ON exchange_rates;
            DROP TRIGGER IF EXISTS exchange_rates_no_delete ON exchange_rates;
            DROP FUNCTION IF EXISTS exchange_rates_reject_mutation();
        SQL);
    }
};
