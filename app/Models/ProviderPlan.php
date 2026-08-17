<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProviderPlan extends Model
{
    use HasFactory;

    protected $fillable = [
        'provider_id',
        'provider_plan_id',
        'name',
        'description',
        'vcpu',
        'ram_mb',
        'disk_gb',
        'bandwidth_gb',
        'price_monthly',
        'currency',
        'price_hourly',
        'cpu_type',
        'architecture',
        'storage_type',
        'deprecated',
        'enabled',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'vcpu' => 'integer',
            'ram_mb' => 'integer',
            'disk_gb' => 'integer',
            'bandwidth_gb' => 'integer',
            'price_monthly' => 'decimal:2',
            'price_hourly' => 'decimal:4',
            'deprecated' => 'boolean',
            'enabled' => 'boolean',
            'metadata' => 'array',
        ];
    }

    public function prices(): HasMany
    {
        return $this->hasMany(ProviderPlanPrice::class);
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(Provider::class);
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }
}
