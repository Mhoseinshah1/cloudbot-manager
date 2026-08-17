<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('provider_plan_prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('provider_plan_id')->constrained()->cascadeOnDelete();
            $table->foreignId('provider_location_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('price_hourly', 10, 4)->nullable();
            $table->decimal('price_monthly', 12, 2)->nullable();
            $table->unsignedBigInteger('included_traffic')->nullable(); // bytes per month
            $table->decimal('price_per_tb_traffic', 12, 4)->nullable();
            $table->string('currency', 3)->default('EUR');
            $table->boolean('deprecated')->default(false);
            $table->timestamps();

            $table->unique(['provider_plan_id', 'provider_location_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('provider_plan_prices');
    }
};
