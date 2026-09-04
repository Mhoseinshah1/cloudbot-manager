<?php

declare(strict_types=1);

use App\Cloud\Enums\ProviderErrorCategory;
use App\Notifications\NotificationChannel;
use App\Notifications\NotificationStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What we tried to tell somebody, and how it went.
 *
 * Support history, mostly. "I never got told my server was ready" is a question
 * only this table can answer, and it answers it whether the send succeeded, was
 * refused because the customer blocked the bot, or never happened because an
 * admin channel was not configured.
 *
 * The summary is a handful of chosen facts — an order number, a server name —
 * and never the message body. A rendered message is the one place a root
 * password could legitimately appear, and copying it here would put a
 * credential into a table nobody thinks of as sensitive.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_logs', function (Blueprint $table): void {
            $table->id();

            // Null for an operational alert: those go to an operator channel,
            // not to a customer, and inventing a user for them would be a lie.
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            // Which intent produced this. Unconstrained on purpose: the log
            // outlives the outbox row and must never be removed by a cascade.
            $table->unsignedBigInteger('outbox_message_id')->nullable();

            $table->string('channel', 40);
            $table->string('type', 100);
            $table->string('status', 30);

            // What makes one delivery attempt recognisable across retries.
            $table->string('deduplication_key')->nullable()->unique();

            // Chosen facts, never a rendered message.
            $table->jsonb('summary')->nullable();

            $table->timestamp('sent_at')->nullable();
            $table->string('error_category', 40)->nullable();

            $table->timestamps();

            $table->index(['user_id', 'created_at']);
            $table->index(['type', 'created_at']);
            $table->index('outbox_message_id');
        });

        foreach ([
            'notification_logs_channel_known' => "channel IN ('".implode("','", NotificationChannel::values())."')",
            'notification_logs_status_known' => "status IN ('".implode("','", NotificationStatus::values())."')",
            'notification_logs_error_category_known' => "error_category IS NULL OR error_category IN ('".implode("','", ProviderErrorCategory::values())."')",
            // Only a delivered notification claims a delivery time.
            'notification_logs_sent_at_matches_status' => "(status = 'sent') = (sent_at IS NOT NULL)",
        ] as $name => $expression) {
            DB::statement("ALTER TABLE notification_logs ADD CONSTRAINT {$name} CHECK ({$expression})");
        }

        // Operational history, retained for the same reason as the rest.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION notification_logs_reject_delete() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'notification_logs is retained operational history: DELETE is not permitted'
                    USING ERRCODE = 'restrict_violation';
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER notification_logs_no_delete
                BEFORE DELETE ON notification_logs
                FOR EACH ROW EXECUTE FUNCTION notification_logs_reject_delete();
        SQL);
    }

    public function down(): void
    {
        if (Schema::hasTable('notification_logs')) {
            DB::unprepared('DROP TRIGGER IF EXISTS notification_logs_no_delete ON notification_logs');
        }

        DB::unprepared('DROP FUNCTION IF EXISTS notification_logs_reject_delete()');

        Schema::dropIfExists('notification_logs');
    }
};
