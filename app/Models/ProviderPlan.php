<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A provider server size, synchronised from the provider.
 *
 * The prices here are what the provider charges us. They are cast to strings by
 * the decimal cast and must stay that way: converting them to a float on the
 * way to a customer price would introduce rounding error into money.
 *
 * @property int $provider_id
 * @property string $provider_plan_id
 * @property string $provider_price_monthly
 * @property string|null $provider_price_hourly
 * @property string $provider_currency
 * @property bool $enabled
 * @property array<string, mixed>|null $metadata
 */
class ProviderPlan extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'provider_id', 'provider_plan_id', 'name', 'vcpu', 'ram_mb', 'disk_gb', 'bandwidth_gb',
        'provider_price_monthly', 'provider_price_hourly', 'provider_currency', 'enabled', 'metadata',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = ['enabled' => true];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'vcpu' => 'integer',
            'ram_mb' => 'integer',
            'disk_gb' => 'integer',
            'bandwidth_gb' => 'integer',
            // decimal casts return strings, which is the point.
            'provider_price_monthly' => 'decimal:6',
            'provider_price_hourly' => 'decimal:6',
            'enabled' => 'boolean',
            'metadata' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Provider, $this>
     */
    public function provider(): BelongsTo
    {
        return $this->belongsTo(Provider::class);
    }
}
