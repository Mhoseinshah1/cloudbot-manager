<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A durable not-before time for one Telegram update.
 *
 * Telegram's 429 carries a `retry_after`, and honouring it by releasing the
 * queue job delays exactly one delivery. A duplicate job already sitting in
 * Redis — Telegram re-delivers, and a dispatch can be duplicated — walks
 * straight past it and calls the API again, which is how a rate limit becomes a
 * longer rate limit.
 *
 * The same lesson the outbox and the server-action barrier learned: queue timing
 * is per delivery and is not durable. The deadline has to be a column every
 * worker re-reads.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('telegram_updates') || Schema::hasColumn('telegram_updates', 'available_at')) {
            return;
        }

        Schema::table('telegram_updates', function (Blueprint $table): void {
            $table->timestamp('available_at')->nullable()->after('processed_at');

            // Recovery asks for unprocessed updates that are due.
            $table->index(['status', 'available_at']);
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('telegram_updates') || ! Schema::hasColumn('telegram_updates', 'available_at')) {
            return;
        }

        Schema::table('telegram_updates', function (Blueprint $table): void {
            $table->dropIndex(['status', 'available_at']);
            $table->dropColumn('available_at');
        });
    }
};
