<?php

namespace App\Models;

use App\Database\Eloquent\IntegerMoneyBuilder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WalletTransaction extends Model
{
    use HasFactory;

    public const TYPE_CREDIT = 'credit';

    public const TYPE_DEBIT = 'debit';

    public const TYPE_REFUND = 'refund';

    public const TYPE_ADJUSTMENT = 'adjustment';

    protected $fillable = [
        'wallet_id',
        'type',
        'amount_toman',
        'balance_after',
        'reference_type',
        'reference_id',
        'description',
    ];

    protected function casts(): array
    {
        return [
            'amount_toman' => 'integer',
            'balance_after' => 'integer',
        ];
    }

    /**
     * PostgreSQL exposes SUM(bigint) as a numeric string; wallet money stays
     * integer toman at the domain boundary on every supported database.
     *
     * @param  \Illuminate\Database\Query\Builder  $query
     * @return IntegerMoneyBuilder<static>
     */
    public function newEloquentBuilder($query): IntegerMoneyBuilder
    {
        return new IntegerMoneyBuilder($query);
    }

    public function wallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class);
    }
}
