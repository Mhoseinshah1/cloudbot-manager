<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProviderPlanPrice extends Model
{
    use HasFactory;

    protected $fillable = [
        'provider_plan_id',
        'provider_location_id',
        'price_hourly',
        'price_monthly',
        'included_traffic',
        'price_per_tb_traffic',
        'currency',
        'deprecated',
    ];

    protected function casts(): array
    {
        return [
            'price_hourly' => 'decimal:4',
            'price_monthly' => 'decimal:2',
            'included_traffic' => 'integer',
            'price_per_tb_traffic' => 'decimal:4',
            'deprecated' => 'boolean',
        ];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(ProviderPlan::class, 'provider_plan_id');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(ProviderLocation::class, 'provider_location_id');
    }
}
