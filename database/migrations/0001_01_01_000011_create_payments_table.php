<?php

declare(strict_types=1);

use App\Enums\PaymentStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * An attempt to put money into a customer's wallet.
 *
 * A payment is not money: creating one moves nothing. Only settlement credits
 * the wallet, and the constraints below are what stop it happening twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table): void {
            $table->id();

            // Restricted: payment history outlives account changes.
            $table->foreignId('user_id')->constrained()->restrictOnDelete();

            // Deliberately a plain column with no foreign key. Orders do not
            // exist yet, and inventing the table to satisfy a constraint would
            // be worse than waiting for the phase that owns it.
            $table->unsignedBigInteger('order_id')->nullable();

            $table->string('gateway', 50);

            $table->bigInteger('amount_toman');
            $table->bigInteger('gateway_fee_toman')->default(0);

            $table->string('status', 20)->default(PaymentStatus::Pending->value);

            // The gateway's own reference. For a manual payment this is the
            // bank reference the operator was given.
            $table->string('provider_payment_id')->nullable();

            $table->string('idempotency_key')->unique();

            // Who accepted it. Null until someone does.
            $table->foreignId('verified_by_admin_id')->nullable()->constrained('users')->nullOnDelete();

            $table->string('receipt_path')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('paid_at')->nullable();

            // Whitelisted facts only; never receipt contents or credentials.
            $table->jsonb('request_metadata')->nullable();
            $table->jsonb('verification_metadata')->nullable();

            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index('status');
        });

        $statuses = implode(', ', array_map(
            static fn (string $value): string => "'{$value}'",
            PaymentStatus::values(),
        ));

        DB::statement("ALTER TABLE payments ADD CONSTRAINT payments_status_check CHECK (status IN ({$statuses}))");
        DB::statement('ALTER TABLE payments ADD CONSTRAINT payments_amount_positive CHECK (amount_toman > 0)');
        DB::statement('ALTER TABLE payments ADD CONSTRAINT payments_fee_non_negative CHECK (gateway_fee_toman >= 0)');

        // One gateway reference settles at most one payment. Partial, so the
        // many payments that have no reference yet are unaffected.
        DB::statement(
            'CREATE UNIQUE INDEX payments_gateway_reference_unique
             ON payments (gateway, provider_payment_id) WHERE provider_payment_id IS NOT NULL'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
