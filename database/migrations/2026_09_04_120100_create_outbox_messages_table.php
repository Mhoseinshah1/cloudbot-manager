<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Work that must happen after a transaction commits, recorded inside it.
 *
 * Telling a customer their refund went through, and then having the transaction
 * roll back, is worse than telling them nothing: they have been told something
 * untrue by a system that cannot take it back. So the intent to notify is
 * written as part of the same transaction as the money, and a worker delivers
 * it afterwards. If the transaction rolls back, so does the promise.
 *
 * This phase only writes rows. The delivery worker and NotificationService
 * arrive with the notification phase; until then these accumulate, which is the
 * correct failure mode — an undelivered intent can still be delivered, while an
 * intent that was never recorded is simply lost.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('outbox_messages', function (Blueprint $table): void {
            $table->id();

            // What happened, as a stable name a consumer can route on.
            $table->string('topic', 100);

            // Which record it happened to. Polymorphic and unconstrained: the
            // message outlives the delivery attempt and must not be deleted by
            // a cascade from something else.
            $table->string('aggregate_type', 100);
            $table->string('aggregate_id', 64);

            // What makes writing the same intent twice impossible. Nullable,
            // because not every future message has a natural key; unique, so
            // the ones that do are enforced by the database rather than by
            // remembering to check.
            $table->string('deduplication_key')->nullable()->unique();

            // Facts only: ids, amounts, names. Never a credential, never a
            // provider response, never a message body assembled here.
            $table->jsonb('payload');

            $table->timestamp('available_at')->useCurrent();
            $table->timestamp('processed_at')->nullable();
            $table->unsignedInteger('attempts')->default(0);

            $table->timestamps();

            // The worker's query: unprocessed, due, oldest first.
            $table->index(['processed_at', 'available_at']);
            $table->index(['aggregate_type', 'aggregate_id']);
        });

        DB::statement('ALTER TABLE outbox_messages ADD CONSTRAINT outbox_messages_attempts_non_negative CHECK (attempts >= 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('outbox_messages');
    }
};
