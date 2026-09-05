<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * API credentials for a provider, encrypted at rest.
 *
 * Release 1.0 uses one active credential set per provider. Superseded sets may
 * remain as inactive rows so a rotation leaves a trail, but only one can be
 * active at a time and the database is what enforces it: "the application only
 * ever activates one" is not a guarantee once two requests race.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('provider_credentials', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('provider_id')->constrained()->cascadeOnDelete();

            // Encrypted by the model's cast. The column is text because
            // ciphertext is not the shape of the data that went in.
            $table->text('credentials');

            $table->boolean('is_active')->default(true);
            $table->timestamp('last_validated_at')->nullable();

            $table->timestamps();
        });

        // Partial unique index: many inactive rows, at most one active.
        DB::statement(
            'CREATE UNIQUE INDEX provider_credentials_one_active_per_provider
             ON provider_credentials (provider_id) WHERE is_active'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('provider_credentials');
    }
};
