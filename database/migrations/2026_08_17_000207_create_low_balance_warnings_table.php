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
            $table->unsignedInteger('threshold_hours');
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

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(
                'CREATE UNIQUE INDEX low_balance_warnings_unresolved_unique '
                .'ON low_balance_warnings (server_id, threshold_hours) '
                .'WHERE resolved_at IS NULL'
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('low_balance_warnings');
    }
};
