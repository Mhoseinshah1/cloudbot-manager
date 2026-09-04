<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A cancelled period may end exactly where it started.
 *
 * The original rule was that a period ends after it begins, which catches an
 * inverted argument list before it becomes an expiry date in the past for a
 * customer who just paid. That is still worth having and is still enforced.
 *
 * What it also forbade was a period of zero length — and once a customer can
 * delete their own server, that is a real state rather than a mistake. Someone
 * who deletes a machine in the same second it was delivered has had exactly no
 * service, and their entitlement must say so: leaving `current_period_end` in
 * the future would tell the renewal sweep that service is owed on a server that
 * no longer exists.
 *
 * So the boundary moves from `>` to `>=`. An inverted period is still refused;
 * a collapsed one is now sayable, because it is sometimes true.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->replaceWith('current_period_end >= current_period_start');
    }

    public function down(): void
    {
        // Rows whose period collapsed would fail the stricter rule. They are
        // service history, so the period is nudged by a second rather than the
        // row being removed: which second a cancelled subscription ended is far
        // less important than the fact that it did.
        DB::statement(
            'UPDATE subscriptions
             SET current_period_end = current_period_start + interval \'1 second\'
             WHERE current_period_end <= current_period_start'
        );

        $this->replaceWith('current_period_end > current_period_start');
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
