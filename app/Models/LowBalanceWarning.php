<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Deduplicated low-balance warning record for an hourly VPS.
 *
 * Future Telegram handlers consume these records (and the
 * LowBalanceWarningTriggered event) to notify customers; the platform itself
 * only records state. At most one unresolved warning per
 * (server, threshold_hours) exists — enforced by a partial unique index.
 */
class LowBalanceWarning extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'server_id',
        'threshold_hours',
        'balance_toman',
        'rate_toman',
        'estimated_hours',
        'warned_at',
        'resolved_at',
        'resolved_reason',
    ];

    protected function casts(): array
    {
        return [
            'threshold_hours' => 'integer',
            'balance_toman' => 'integer',
            'rate_toman' => 'integer',
            'estimated_hours' => 'integer',
            'warned_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    public function scopeUnresolved(Builder $query): Builder
    {
        return $query->whereNull('resolved_at');
    }
}
