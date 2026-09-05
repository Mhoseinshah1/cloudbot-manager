<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One successful delivery per intent, and an honest record of the attempts.
 *
 * The original constraint made `deduplication_key` unique across every row,
 * which conflated two different jobs the specification gives this table:
 * deduplication and support history. It deduplicated attempts rather than
 * sends — so a first attempt recorded as `undeliverable`, because no admin
 * channel was configured, permanently occupied the key and left no way to
 * record the delivery that succeeded after somebody configured one. The
 * history then said the alert was undeliverable, which stopped being true.
 *
 * The rule that was actually wanted is narrower: at most one *successful*
 * delivery per logical intent. That is a partial unique index, and it leaves
 * the unsuccessful attempts free to accumulate as what they are — the record
 * of what was tried, which is the support history half of the job.
 *
 * Deduplication is not weakened by this. What stops a second send is the
 * check made before the send, against exactly this index; what stops two rows
 * claiming the same success is the index itself.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('notification_logs')) {
            return;
        }

        // Laravel named it on creation via ->unique().
        DB::statement('ALTER TABLE notification_logs DROP CONSTRAINT IF EXISTS notification_logs_deduplication_key_unique');
        DB::statement('DROP INDEX IF EXISTS notification_logs_deduplication_key_unique');

        DB::statement(
            "CREATE UNIQUE INDEX notification_logs_one_successful_delivery
             ON notification_logs (deduplication_key)
             WHERE deduplication_key IS NOT NULL AND status = 'sent'"
        );

        // The pre-send lookup: every terminal outcome for one intent.
        DB::statement(
            'CREATE INDEX notification_logs_delivery_lookup
             ON notification_logs (deduplication_key, status)'
        );
    }

    public function down(): void
    {
        if (! Schema::hasTable('notification_logs')) {
            return;
        }

        DB::statement('DROP INDEX IF EXISTS notification_logs_one_successful_delivery');
        DB::statement('DROP INDEX IF EXISTS notification_logs_delivery_lookup');

        // Restoring a global unique would fail wherever an intent legitimately
        // accumulated several attempts, and those rows are support history. The
        // duplicates keep their content and lose only the key they share, so
        // nothing about what was attempted is erased.
        DB::statement(
            'UPDATE notification_logs SET deduplication_key = NULL
             WHERE id NOT IN (
                 SELECT MIN(id) FROM notification_logs
                 WHERE deduplication_key IS NOT NULL
                 GROUP BY deduplication_key
             )
             AND deduplication_key IS NOT NULL'
        );

        DB::statement(
            'ALTER TABLE notification_logs
             ADD CONSTRAINT notification_logs_deduplication_key_unique UNIQUE (deduplication_key)'
        );
    }
};
