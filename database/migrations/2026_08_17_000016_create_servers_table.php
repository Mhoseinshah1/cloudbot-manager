<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('servers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('provider_id')->constrained()->cascadeOnDelete();
            $table->string('provider_server_id')->nullable();
            $table->string('name');
            $table->string('ip_address')->nullable();
            $table->foreignId('provider_location_id')->nullable()->constrained()->nullOnDelete();
            $table->json('plan_snapshot')->nullable();
            $table->json('image_snapshot')->nullable();
            $table->string('status')->default('pending'); // pending, provisioning, running, off, suspended, rebuilding, deleting, deleted, error
            $table->string('power_state')->default('off'); // running, off
            $table->decimal('provider_cost', 12, 2)->nullable();
            $table->string('provider_currency', 3)->nullable();
            $table->decimal('exchange_rate', 14, 4)->nullable();
            $table->unsignedBigInteger('local_cost')->nullable();
            $table->unsignedBigInteger('selling_price')->nullable();
            $table->bigInteger('gross_margin')->nullable();
            $table->text('root_password_encrypted')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('suspended_at')->nullable();
            $table->softDeletes();
            $table->timestamps();

            $table->unique(['provider_id', 'provider_server_id']);
            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('servers');
    }
};
