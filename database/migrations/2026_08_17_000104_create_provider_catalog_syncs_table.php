<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('provider_catalog_syncs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('provider_id')->constrained()->cascadeOnDelete();
            $table->string('status')->default('running'); // running, completed, failed
            $table->unsignedInteger('locations_count')->default(0);
            $table->unsignedInteger('plans_count')->default(0);
            $table->unsignedInteger('images_count')->default(0);
            $table->unsignedInteger('pricing_count')->default(0);
            $table->json('errors')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['provider_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('provider_catalog_syncs');
    }
};
