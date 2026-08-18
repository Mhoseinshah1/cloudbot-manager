<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->string('billing_mode')->default('monthly')->after('billing_cycle');
            $table->unsignedBigInteger('hourly_rate_toman')->nullable()->after('price_toman');
            $table->unsignedBigInteger('monthly_cap_toman')->nullable()->after('hourly_rate_toman');
        });
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropColumn(['billing_mode', 'hourly_rate_toman', 'monthly_cap_toman']);
        });
    }
};
