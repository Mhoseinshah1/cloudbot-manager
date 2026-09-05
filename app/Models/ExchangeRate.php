<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ExchangeRateSource;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What one currency was worth in Toman, from a given moment.
 *
 * A row here is a historical fact. A new rate is a new row; the old one keeps
 * describing what things were priced at while it applied. Nothing in the
 * application updates these, and every order that snapshots a rate points at
 * the row it used.
 *
 * The rate is a decimal cast to a string on purpose. It is multiplied by
 * provider costs to produce money, and a float would make that multiplication
 * approximately right.
 *
 * @property string $currency
 * @property string $rate_to_toman
 * @property ExchangeRateSource $source
 * @property \Illuminate\Support\Carbon $effective_from
 * @property int|null $created_by_admin_id
 */
class ExchangeRate extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'currency', 'rate_to_toman', 'source', 'effective_from', 'created_by_admin_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            // Eight places, matching the column. A string, not a float.
            'rate_to_toman' => 'decimal:8',
            'source' => ExchangeRateSource::class,
            'effective_from' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function createdByAdmin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_admin_id');
    }
}
