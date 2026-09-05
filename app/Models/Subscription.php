<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\BillingCycle;
use App\Enums\BillingMode;
use App\Enums\SubscriptionStatus;
use App\Exceptions\FinancialRecordDeletionForbidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * How long a server has been paid for.
 *
 * `current_period_end` is the authoritative expiry and the only one. Anything
 * that wants to know when service stops asks this column; there is deliberately
 * no competing field on the server.
 *
 * A period is exactly {@see self::PERIOD_SECONDS} seconds — 30 × 24 hours,
 * recorded in docs/decisions/ADR-001 — and is established once, when the server
 * is first delivered. A duplicated recovery must never move it: a customer's
 * service does not restart because a worker ran twice.
 *
 * @property int $user_id
 * @property int $server_id
 * @property int $product_id
 * @property SubscriptionStatus $status
 * @property \Illuminate\Support\Carbon $current_period_start
 * @property \Illuminate\Support\Carbon $current_period_end
 * @property int $price_toman
 * @property BillingCycle $billing_cycle
 * @property BillingMode $billing_mode
 * @property bool $cancel_at_period_end
 * @property \Illuminate\Support\Carbon|null $cancelled_at
 * @property \Illuminate\Support\Carbon|null $grace_until
 * @property \Illuminate\Support\Carbon|null $last_billed_at
 * @property \Illuminate\Support\Carbon|null $next_billing_at
 * @property-read User $user
 * @property-read Server $server
 */
class Subscription extends Model
{
    /**
     * One monthly service period, in seconds.
     *
     * 30 × 24 × 3600. Fixed elapsed time, not a calendar month: February and
     * March would otherwise buy different amounts of service for the same
     * money, and the rule for a subscription starting on the 31st is a business
     * decision nobody made. See docs/decisions/ADR-001.
     */
    public const PERIOD_SECONDS = 2_592_000;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id', 'server_id', 'product_id', 'status', 'current_period_start',
        'current_period_end', 'price_toman', 'billing_cycle', 'billing_mode',
        'cancel_at_period_end',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => SubscriptionStatus::class,
            'billing_cycle' => BillingCycle::class,
            'billing_mode' => BillingMode::class,
            'current_period_start' => 'datetime',
            'current_period_end' => 'datetime',
            'cancelled_at' => 'datetime',
            'grace_until' => 'datetime',
            'last_billed_at' => 'datetime',
            'next_billing_at' => 'datetime',
            // Whole Toman. An int, never a float.
            'price_toman' => 'integer',
            'cancel_at_period_end' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Server, $this>
     */
    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    /** How long this period runs, in seconds. */
    public function periodSeconds(): int
    {
        return $this->current_period_end->getTimestamp() - $this->current_period_start->getTimestamp();
    }

    /**
     * Refuse deletion. Service history is retained like financial history: it
     * is what says a customer was owed the service they were charged for.
     */
    protected static function booted(): void
    {
        static::deleting(static function (self $subscription): never {
            throw FinancialRecordDeletionForbidden::forSubscription();
        });
    }
}
