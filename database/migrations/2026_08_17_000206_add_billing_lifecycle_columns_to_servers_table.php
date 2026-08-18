<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('servers', function (Blueprint $table) {
            // Service-anchored billing (cap) period for hourly_capped products.
            // Cap resets only when this service period advances — never on
            // arbitrary calendar-month boundaries.
            $table->timestamp('billing_period_started_at')->nullable()->after('billing_stopped_at');
            $table->timestamp('billing_period_ends_at')->nullable()->after('billing_period_started_at');
            $table->unsignedBigInteger('current_period_charged')->default(0)->after('billing_period_ends_at');

            // Insufficient-balance lifecycle state machine.
            $table->string('billing_state')->default('active')->after('current_period_charged');
            $table->timestamp('billing_state_changed_at')->nullable()->after('billing_state');
            $table->timestamp('grace_started_at')->nullable()->after('billing_state_changed_at');
            $table->timestamp('grace_ends_at')->nullable()->after('grace_started_at');
            $table->timestamp('lifecycle_action_performed_at')->nullable()->after('grace_ends_at');
        });
    }

    public function down(): void
    {
        Schema::table('servers', function (Blueprint $table) {
            $table->dropColumn([
                'billing_period_started_at',
                'billing_period_ends_at',
                'current_period_charged',
                'billing_state',
                'billing_state_changed_at',
                'grace_started_at',
                'grace_ends_at',
                'lifecycle_action_performed_at',
            ]);
        });
    }
};
