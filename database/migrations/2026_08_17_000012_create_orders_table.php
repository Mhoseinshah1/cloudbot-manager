<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->string('order_number')->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('status')->default('pending'); // pending, paid, failed, provisioning, provisioned, cancelled, expired
            $table->unsignedBigInteger('total_toman')->default(0);
            $table->unsignedBigInteger('discount_toman')->default(0);
            $table->foreignId('coupon_id')->nullable()->constrained()->nullOnDelete();
            $table->string('gateway_code')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('provisioned_at')->nullable();
            $table->json('cost_snapshot')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
