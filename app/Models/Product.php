<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Product extends Model
{
    use HasFactory;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_INACTIVE = 'inactive';

    public const BILLING_MONTHLY = 'monthly';

    public const BILLING_QUARTERLY = 'quarterly';

    public const BILLING_YEARLY = 'yearly';

    public const MARKUP_FIXED = 'fixed';

    public const MARKUP_PERCENTAGE = 'percentage';

    public const MARKUP_CUSTOM = 'custom';

    protected $fillable = [
        'provider_id',
        'provider_plan_id',
        'name',
        'slug',
        'description',
        'status',
        'billing_cycle',
        'markup_strategy',
        'markup_value',
        'price_toman',
        'lifecycle_policy',
        'enabled',
    ];

    protected function casts(): array
    {
        return [
            'markup_value' => 'decimal:2',
            'price_toman' => 'integer',
            'lifecycle_policy' => 'array',
            'enabled' => 'boolean',
        ];
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(Provider::class);
    }

    public function providerPlan(): BelongsTo
    {
        return $this->belongsTo(ProviderPlan::class, 'provider_plan_id');
    }

    public function prices(): HasMany
    {
        return $this->hasMany(ProductPrice::class);
    }

    public function orderItems(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }
}
