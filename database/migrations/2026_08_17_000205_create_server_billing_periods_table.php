<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('server_billing_periods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('server_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subscription_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('period_start');
            $table->timestamp('period_end');
            // Customer rate in integer toman per one-hour billing unit.
            $table->unsignedBigInteger('rate_toman');
            // Actually charged amount (integer toman). May be less than rate
            // when a monthly cap is reached mid-period, or 0 for unpaid periods.
            $table->unsignedBigInteger('amount_toman');
            $table->string('currency', 3)->default('IRR');
            $table->string('status')->default('paid'); // paid, unpaid
            $table->boolean('capped')->default(false);
            // Reference to the wallet transaction that settled this charge.
            $table->string('reference_type')->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->string('description')->nullable();
            $table->timestamps();

            // Idempotency: the same one-hour billing interval can never be
            // charged twice for the same server.
            $table->unique(['server_id', 'period_start', 'period_end']);
            $table->index(['server_id', 'status']);
            $table->index(['server_id', 'period_start']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('server_billing_periods');
    }
};
