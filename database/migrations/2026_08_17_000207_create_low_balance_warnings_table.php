<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('low_balance_warnings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('server_id')->constrained()->cascadeOnDelete();
            // Threshold in hours of remaining usage (e.g. 24, 12, 6).
            $table->unsignedInteger('threshold_hours');
            // Snapshot at warning time — integer toman, never floats.
            $table->unsignedBigInteger('balance_toman');
            $table->unsignedBigInteger('rate_toman');
            $table->unsignedInteger('estimated_hours');
            $table->timestamp('warned_at');
            $table->timestamp('resolved_at')->nullable();
            $table->string('resolved_reason')->nullable();
            $table->timestamps();

            // Deduplication: at most one unresolved warning per threshold.
            // Only the database can authoritatively prevent duplicate warnings
            // when the scheduler runs concurrently. Partial indexes are
            // PostgreSQL-only (Laravel's SQLite grammar cannot emit the
            // WHERE clause); on SQLite the service's own pre-check guards
            // against duplicates, which is sufficient for dev/test.
            if (DB::getDriverName() === 'pgsql') {
                $table->unique(['server_id', 'threshold_hours'])->whereNull('resolved_at');
            }
            $table->index(['server_id', 'resolved_at']);
            $table->index(['user_id', 'resolved_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('low_balance_warnings');
    }
};
