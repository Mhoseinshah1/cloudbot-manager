<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('provider_locations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('provider_id')->constrained()->cascadeOnDelete();
            $table->string('provider_location_id');
            $table->string('name');
            $table->string('country_code', 2)->nullable();
            $table->string('city')->nullable();
            $table->boolean('enabled')->default(true);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['provider_id', 'provider_location_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('provider_locations');
    }
};
