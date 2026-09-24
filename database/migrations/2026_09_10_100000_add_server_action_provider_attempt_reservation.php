<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When a provider write was reserved, so the reconciler knows to wait.
 *
 * A reserved action reads as `pending`, no handle, `attempts > 0`, no retry
 * evidence — and that shape means a provider write may be in flight this
 * second. Nothing in the row said *when* it started, so a reconciler that
 * acquired the server's lock (which it can, once a Redis TTL expires) could
 * park a delete that was still succeeding.
 *
 * The timestamp is the missing fact. Null means no provider write is
 * outstanding; a value means one was started then, and how long ago decides
 * whether the original worker still owns the opportunity to record its result.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('server_actions') || Schema::hasColumn('server_actions', 'provider_attempt_reserved_at')) {
            return;
        }

        Schema::table('server_actions', function (Blueprint $table): void {
            $table->timestamp('provider_attempt_reserved_at')->nullable()->after('retry_after');

            // The reconciler filters open actions by how long a reservation has
            // been outstanding.
            $table->index(['status', 'provider_attempt_reserved_at']);
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('server_actions') || ! Schema::hasColumn('server_actions', 'provider_attempt_reserved_at')) {
            return;
        }

        Schema::table('server_actions', function (Blueprint $table): void {
            $table->dropIndex(['status', 'provider_attempt_reserved_at']);
            $table->dropColumn('provider_attempt_reserved_at');
        });
    }
};
