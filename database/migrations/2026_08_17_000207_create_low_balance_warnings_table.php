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

            $table->index(['server_id', 'resolved_at']);
            $table->index(['user_id', 'resolved_at']);
        });

        // Partial unique index: at most one UNRESOLVED warning per
        // (server_id, threshold_hours). Laravel's Schema builder cannot
        // express WHERE clauses on indexes, so we use driver-specific DDL.
        $driver = DB::getDriverName();

        if ($driver === 'pgsql') {
            DB::statement(
                'CREATE UNIQUE INDEX low_balance_warnings_server_threshold_unresolved_unique '
                .'ON low_balance_warnings (server_id, threshold_hours) '
                .'WHERE resolved_at IS NULL'
            );
        } elseif ($driver === 'sqlite') {
            DB::statement(
                'CREATE UNIQUE INDEX low_balance_warnings_server_threshold_unresolved_unique '
                .'ON low_balance_warnings (server_id, threshold_hours) '
                .'WHERE resolved_at IS NULL'
            );
        }
    }

    public function down(): void
    {
        $driver = DB::getDriverName();

        if ($driver === 'pgsql' || $driver === 'sqlite') {
            DB::statement(
                'DROP INDEX IF EXISTS low_balance_warnings_server_threshold_unresolved_unique'
            );
        }

        Schema::dropIfExists('low_balance_warnings');
    }
};
