<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProviderLocation extends Model
{
    use HasFactory;

    protected $fillable = [
        'provider_id',
        'provider_location_id',
        'name',
        'country_code',
        'city',
        'network_zone',
        'enabled',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'metadata' => 'array',
        ];
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(Provider::class);
    }

    public function servers(): HasMany
    {
        return $this->hasMany(Server::class);
    }
}
