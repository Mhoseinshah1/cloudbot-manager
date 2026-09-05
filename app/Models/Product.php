<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\BillingCycle;
use App\Enums\BillingMode;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Something a customer can buy.
 *
 * The sales abstraction over a provider plan. It carries no copy of the plan's
 * specification: a re-sync updates the plan, and a product that had duplicated
 * vcpu or memory would quietly start describing a machine nobody sells.
 *
 * `active` is the operator's decision to offer it. Whether it can actually be
 * sold right now depends on the provider, the plan and the location too, and
 * that judgement belongs to PricingService rather than to any single flag here.
 *
 * @property int $provider_id
 * @property int $provider_plan_id
 * @property string $name
 * @property string|null $description
 * @property bool $active
 * @property BillingMode $billing_mode
 * @property BillingCycle $billing_cycle
 * @property int $sort_order
 * @property-read Provider $provider
 * @property-read ProviderPlan $providerPlan
 */
class Product extends Model
{
    /** @use HasFactory<\Database\Factories\ProductFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'provider_id', 'provider_plan_id', 'name', 'description',
        'active', 'billing_mode', 'billing_cycle', 'sort_order',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'active' => true,
        'billing_mode' => BillingMode::Monthly->value,
        'billing_cycle' => BillingCycle::Monthly->value,
        'sort_order' => 0,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'billing_mode' => BillingMode::class,
            'billing_cycle' => BillingCycle::class,
            'sort_order' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Provider, $this>
     */
    public function provider(): BelongsTo
    {
        return $this->belongsTo(Provider::class);
    }

    /**
     * @return BelongsTo<ProviderPlan, $this>
     */
    public function providerPlan(): BelongsTo
    {
        return $this->belongsTo(ProviderPlan::class);
    }

    /**
     * @return HasMany<ProductLocationPrice, $this>
     */
    public function locationPrices(): HasMany
    {
        return $this->hasMany(ProductLocationPrice::class);
    }
}
