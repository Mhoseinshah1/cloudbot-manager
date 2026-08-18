<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('servers', function (Blueprint $table) {
            $table->string('billing_mode')->default('monthly')->after('product_id');
            // Customer billing rates snapshot (never provider cost).
            $table->unsignedBigInteger('hourly_rate_toman')->nullable()->after('selling_price');
            $table->unsignedBigInteger('monthly_cap_toman')->nullable()->after('hourly_rate_toman');
            // Hourly billing lifecycle timestamps.
            $table->timestamp('billing_started_at')->nullable()->after('monthly_cap_toman');
            $table->timestamp('last_billed_at')->nullable()->after('billing_started_at');
            $table->timestamp('billing_stopped_at')->nullable()->after('last_billed_at');
        });
    }

    public function down(): void
    {
        Schema::table('servers', function (Blueprint $table) {
            $table->dropColumn([
                'billing_mode',
                'hourly_rate_toman',
                'monthly_cap_toman',
                'billing_started_at',
                'last_billed_at',
                'billing_stopped_at',
            ]);
        });
    }
};
