<?php

namespace App\Models;

use App\Database\Eloquent\IntegerMoneyBuilder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Immutable ledger of every hourly billing interval charged (or attempted)
 * against a server. The (server_id, period_start, period_end) unique index
 * guarantees an interval can never be charged twice.
 */
class ServerBillingPeriod extends Model
{
    use HasFactory;

    public const STATUS_PAID = 'paid';

    public const STATUS_UNPAID = 'unpaid';

    public const CURRENCY_IRR = 'IRR';

    protected $fillable = [
        'server_id',
        'subscription_id',
        'period_start',
        'period_end',
        'rate_toman',
        'amount_toman',
        'currency',
        'status',
        'capped',
        'reference_type',
        'reference_id',
        'description',
    ];

    protected function casts(): array
    {
        return [
            'period_start' => 'datetime',
            'period_end' => 'datetime',
            'rate_toman' => 'integer',
            'amount_toman' => 'integer',
            'capped' => 'boolean',
        ];
    }

    /**
     * @param  \Illuminate\Database\Query\Builder  $query
     * @return IntegerMoneyBuilder<static>
     */
    public function newEloquentBuilder($query): IntegerMoneyBuilder
    {
        return new IntegerMoneyBuilder($query);
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    public function reference(): MorphTo
    {
        return $this->morphTo();
    }
}
