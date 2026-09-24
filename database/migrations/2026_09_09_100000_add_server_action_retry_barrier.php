<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A durable not-before time for retrying one server action.
 *
 * Server actions had no way to say "not yet". A provider that answered with a
 * rate limit, an outage or a transient error left only two options in code:
 * settle the action as permanently failed, which threw away a customer's power
 * off or delete because the provider was busy for a minute, or release the one
 * queue delivery — which delays this worker and says nothing at all to a
 * duplicate job already sitting in Redis.
 *
 * The same lesson the outbox learned. Queue timing is per delivery and is not
 * durable; the barrier has to be a column, checked by every worker after it
 * takes the server's lock, so a job enqueued before the deadline was written
 * still respects it.
 *
 * Nullable, because most actions never need one: null means "no barrier", which
 * is what an action that has never been refused should say.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('server_actions') || Schema::hasColumn('server_actions', 'retry_after')) {
            return;
        }

        Schema::table('server_actions', function (Blueprint $table): void {
            $table->timestamp('retry_after')->nullable()->after('attempts');

            // The reconciler asks for open actions that are due, so the two
            // columns it filters on are indexed together.
            $table->index(['status', 'retry_after']);
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('server_actions') || ! Schema::hasColumn('server_actions', 'retry_after')) {
            return;
        }

        Schema::table('server_actions', function (Blueprint $table): void {
            $table->dropIndex(['status', 'retry_after']);
            $table->dropColumn('retry_after');
        });
    }
};
