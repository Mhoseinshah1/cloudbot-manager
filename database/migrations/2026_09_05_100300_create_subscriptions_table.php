<?php

declare(strict_types=1);

use App\Enums\BillingCycle;
use App\Enums\BillingMode;
use App\Enums\SubscriptionStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * How long a server has been paid for.
 *
 * `current_period_end` is the only expiry this system has. Not the newest of
 * several columns, not a value derived at read time — the one field, and the
 * reason `servers` deliberately has no `expires_at`. Two sources of truth about
 * when service stops would eventually disagree, and the day they do a customer
 * either loses a server they paid for or keeps one they did not.
 *
 * A period is exactly 2,592,000 seconds — 30 × 24 hours — recorded in
 * docs/decisions/ADR-001. Not a calendar month: February and March would
 * otherwise cost the same for three days' difference in service, and the
 * overflow rule for the 31st is a business decision nobody made.
 *
 * Phase 7 writes exactly one row per server, once, at delivery. Renewal, grace
 * and termination belong to Phase 11; the columns they need exist and stay null
 * rather than being given invented meanings now.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscriptions', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('user_id')->constrained()->restrictOnDelete();

            // One subscription per server. Unique, so a duplicated recovery
            // cannot produce a second period that a customer is billed for.
            $table->foreignId('server_id')->unique()->constrained()->restrictOnDelete();

            $table->foreignId('product_id')->constrained()->restrictOnDelete();

            $table->string('status', 30)->default(SubscriptionStatus::Active->value);

            // The authoritative period. Both in UTC, like everything stored.
            $table->timestamp('current_period_start');
            $table->timestamp('current_period_end');

            // What this period cost. Whole Toman, BIGINT, PHP int — the same
            // number the customer was charged for the order.
            $table->bigInteger('price_toman');

            $table->string('billing_cycle', 20);
            $table->string('billing_mode', 20);

            $table->boolean('cancel_at_period_end')->default(false);

            $table->timestamp('cancelled_at')->nullable();

            // Phase 11's, all three. Left null here rather than filled with a
            // guess: a grace deadline or a next-billing date invented now would
            // be a policy decision made by a phase that has no policy.
            $table->timestamp('grace_until')->nullable();
            $table->timestamp('last_billed_at')->nullable();
            $table->timestamp('next_billing_at')->nullable();

            $table->timestamps();

            $table->index(['user_id', 'status']);
            // Phase 11's sweep: what expires next.
            $table->index(['status', 'current_period_end']);
        });

        $this->checkIn('subscriptions', 'status', SubscriptionStatus::values());
        $this->checkIn('subscriptions', 'billing_cycle', BillingCycle::values());
        $this->checkIn('subscriptions', 'billing_mode', BillingMode::values());

        DB::statement(
            'ALTER TABLE subscriptions ADD CONSTRAINT subscriptions_price_positive CHECK (price_toman > 0)'
        );

        // A period that ends before it starts is not a period. Cheap to state,
        // and it catches an inverted argument list before it becomes an expiry
        // date in the past for a customer who just paid.
        DB::statement(
            'ALTER TABLE subscriptions ADD CONSTRAINT subscriptions_period_ordered
             CHECK (current_period_end > current_period_start)'
        );

        // Retained as service history, alongside the server it belongs to.
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER subscriptions_no_delete
                BEFORE DELETE ON subscriptions
                FOR EACH ROW EXECUTE FUNCTION financial_records_reject_delete();
        SQL);
    }

    public function down(): void
    {
        if (Schema::hasTable('subscriptions')) {
            DB::unprepared('DROP TRIGGER IF EXISTS subscriptions_no_delete ON subscriptions');
        }

        Schema::dropIfExists('subscriptions');
    }

    /**
     * @param  list<string>  $values
     */
    private function checkIn(string $table, string $column, array $values, bool $nullable = false): void
    {
        $list = implode(', ', array_map(static fn (string $v): string => "'{$v}'", $values));
        $null = $nullable ? "{$column} IS NULL OR " : '';

        DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$table}_{$column}_check CHECK ({$null}{$column} IN ({$list}))");
    }
};
