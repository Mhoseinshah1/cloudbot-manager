<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('provider_plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('provider_id')->constrained()->cascadeOnDelete();
            $table->string('provider_plan_id');
            $table->string('name');
            $table->text('description')->nullable();
            $table->unsignedInteger('vcpu')->default(1);
            $table->unsignedInteger('ram_mb')->default(1024);
            $table->unsignedInteger('disk_gb')->default(20);
            $table->unsignedBigInteger('bandwidth_gb')->nullable();
            $table->decimal('price_monthly', 12, 2);
            $table->string('currency', 3)->default('EUR');
            $table->decimal('price_hourly', 10, 4)->nullable();
            $table->boolean('enabled')->default(true);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['provider_id', 'provider_plan_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('provider_plans');
    }
};
