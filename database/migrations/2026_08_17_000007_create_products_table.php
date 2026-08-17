<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('provider_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('provider_plan_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->string('status')->default('active'); // draft, active, inactive
            $table->string('billing_cycle')->default('monthly'); // monthly, quarterly, yearly
            $table->string('markup_strategy')->default('percentage'); // fixed, percentage, custom
            $table->decimal('markup_value', 10, 2)->default(0);
            $table->unsignedBigInteger('price_toman')->nullable(); // explicit price for custom strategy
            $table->json('lifecycle_policy')->nullable();
            $table->boolean('enabled')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
