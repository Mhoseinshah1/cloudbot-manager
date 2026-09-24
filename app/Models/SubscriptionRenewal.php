<?php

declare(strict_types=1);

namespace App\Models;

use App\Exceptions\FinancialRecordDeletionForbidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One paid extension of one subscription period.
 *
 * The durable financial identity of a renewal. Its unique idempotency key is
 * what makes "charge for this period" happen at most once however many
 * confirmations, retries and re-delivered callbacks arrive, and the row is what
 * a replay reads instead of charging again.
 *
 * @property int $subscription_id
 * @property int $user_id
 * @property int|null $invoice_id
 * @property \Illuminate\Support\Carbon $old_period_end
 * @property \Illuminate\Support\Carbon $new_period_end
 * @property int $amount_toman
 * @property string $idempotency_key
 */
class SubscriptionRenewal extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'subscription_id', 'user_id', 'invoice_id',
        'old_period_end', 'new_period_end', 'amount_toman', 'idempotency_key',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'old_period_end' => 'datetime',
            'new_period_end' => 'datetime',
            // Whole Toman. An int, never a float.
            'amount_toman' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Subscription, $this>
     */
    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    /**
     * @return BelongsTo<Invoice, $this>
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /**
     * The identity of one renewal: this subscription, this period.
     *
     * Derived from the period being replaced rather than from a random token,
     * so two independent confirmations of the same renewal produce the same key
     * and the unique index decides between them. A random identity would make
     * them two different renewals and charge twice.
     */
    public static function keyFor(int $subscriptionId, int $oldPeriodEndEpoch): string
    {
        return 'renewal:'.$subscriptionId.':'.$oldPeriodEndEpoch;
    }

    /** Append-only, and the database enforces it too. */
    protected static function booted(): void
    {
        static::updating(static function (self $renewal): never {
            throw FinancialRecordDeletionForbidden::forSubscriptionRenewal();
        });

        static::deleting(static function (self $renewal): never {
            throw FinancialRecordDeletionForbidden::forSubscriptionRenewal();
        });
    }
}
