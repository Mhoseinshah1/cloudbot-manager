<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            // Explicit customer billing mode: monthly | hourly | hourly_capped.
            $table->string('billing_mode')->default('monthly')->after('billing_cycle');
            // Customer hourly selling price (hourly / hourly_capped products).
            $table->unsignedBigInteger('hourly_price_toman')->nullable()->after('price_toman');
            // Customer monthly cap for hourly_capped products.
            $table->unsignedBigInteger('monthly_cap_toman')->nullable()->after('hourly_price_toman');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['billing_mode', 'hourly_price_toman', 'monthly_cap_toman']);
        });
    }
};
