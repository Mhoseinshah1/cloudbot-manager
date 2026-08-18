<?php

namespace App\Models;

use App\Enums\BillingMode;
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

    public const BILLING_HOURLY = 'hourly';

    public const BILLING_HOURLY_CAPPED = 'hourly_capped';

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
        'billing_mode',
        'markup_strategy',
        'markup_value',
        'price_toman',
        'hourly_price_toman',
        'monthly_cap_toman',
        'lifecycle_policy',
        'enabled',
    ];

    protected function casts(): array
    {
        return [
            'markup_value' => 'decimal:2',
            'price_toman' => 'integer',
            'hourly_price_toman' => 'integer',
            'monthly_cap_toman' => 'integer',
            'billing_mode' => BillingMode::class,
            'lifecycle_policy' => 'array',
            'enabled' => 'boolean',
        ];
    }

    /**
     * Explicit billing mode; never inferred from provider pricing.
     */
    public function billingMode(): BillingMode
    {
        return $this->billing_mode ?? BillingMode::Monthly;
    }

    public function isHourlyBilling(): bool
    {
        return $this->billingMode()->isHourly();
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
