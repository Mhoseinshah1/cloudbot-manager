<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The cloud providers this installation can buy from.
 *
 * There is deliberately no column naming a PHP class. The code selects an
 * implementation from a static registry in config, and this row only says which
 * registry key is in play. A class name in the database would mean a write to
 * this table could get arbitrary code instantiated.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('providers', function (Blueprint $table): void {
            $table->id();

            // Matches a key in config/providers.php.
            $table->string('code', 50)->unique();
            $table->string('name');

            // Operator kill switch for one provider, without deleting its
            // history or the servers already running on it.
            $table->boolean('enabled')->default(true);

            // Safe operational settings only. Credentials live in their own
            // encrypted table.
            $table->jsonb('settings')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('providers');
    }
};
