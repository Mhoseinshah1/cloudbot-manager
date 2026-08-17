<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('billing_cycle')->default('monthly');
            $table->unsignedBigInteger('price_toman');
            $table->decimal('provider_cost', 12, 2);
            $table->string('provider_currency', 3)->default('EUR');
            $table->decimal('exchange_rate', 14, 4);
            $table->unsignedBigInteger('local_cost');
            $table->bigInteger('gross_margin'); // price_toman - local_cost
            $table->timestamp('valid_from')->nullable();
            $table->timestamp('valid_to')->nullable();
            $table->timestamps();

            $table->index(['product_id', 'billing_cycle']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_prices');
    }
};
