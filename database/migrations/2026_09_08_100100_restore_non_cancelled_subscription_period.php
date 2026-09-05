<?php

declare(strict_types=1);

use App\Enums\SubscriptionStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Only a cancelled subscription may have a period of no length.
 *
 * The previous migration relaxed the rule from `>` to `>=` so that a customer
 * deleting a server in the same second it arrived could have their entitlement
 * end honestly. That was the right outcome and too broad a rule: it also made a
 * zero-length *active* subscription representable, and an active subscription
 * that expires the instant it begins is a customer who paid for thirty days and
 * is owed none of them. Nothing writes one today, which is exactly why the
 * database should be the thing that says it cannot happen.
 *
 * So the boundary becomes state-aware. A cancelled period may collapse; every
 * other status keeps the original rule. An inverted period is still refused
 * everywhere, which was the point of the constraint to begin with.
 *
 * The fixed 2,592,000-second initial period is untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        $cancelled = SubscriptionStatus::Cancelled->value;

        $this->replaceWith(
            "current_period_end > current_period_start
             OR (status = '{$cancelled}' AND current_period_end = current_period_start)"
        );
    }

    public function down(): void
    {
        // Back to the broader rule. Nothing is rewritten and no history is
        // touched: every row that satisfies the state-aware rule satisfies the
        // looser one as well.
        $this->replaceWith('current_period_end >= current_period_start');
    }

    private function replaceWith(string $expression): void
    {
        if (! Schema::hasTable('subscriptions')) {
            return;
        }

        DB::statement('ALTER TABLE subscriptions DROP CONSTRAINT IF EXISTS subscriptions_period_ordered');
        DB::statement("ALTER TABLE subscriptions ADD CONSTRAINT subscriptions_period_ordered CHECK ({$expression})");
    }
};
