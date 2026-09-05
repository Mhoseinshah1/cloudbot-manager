<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What one product costs, and sells for, in one location.
 *
 * Two different kinds of number live here and must not be confused.
 * `selling_price_toman` is customer money: whole Toman, a PHP int, decided by
 * an operator and quoted to the customer exactly as stored. Nothing derives it
 * from the cost, and nothing is added on top of it — Release 1.0 folds provider
 * add-ons such as a required IPv4 into this figure when it is set.
 *
 * `provider_cost_snapshot` is what the provider charges us, in the provider's
 * currency, at its own decimal scale. It exists to compute margin, never price.
 * The decimal cast returns it as a string and it must stay one: a float here
 * would put rounding error into a financial report.
 *
 * @property int $product_id
 * @property int $provider_location_id
 * @property bool $active
 * @property int $selling_price_toman
 * @property string|null $provider_cost_snapshot
 * @property string $provider_currency
 * @property int|null $default_image_id
 * @property-read Product $product
 * @property-read ProviderLocation $providerLocation
 * @property-read ProviderImage|null $defaultImage
 */
class ProductLocationPrice extends Model
{
    /** @use HasFactory<\Database\Factories\ProductLocationPriceFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'product_id', 'provider_location_id', 'active', 'selling_price_toman',
        'provider_cost_snapshot', 'provider_currency', 'default_image_id',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = ['active' => true];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            // int, because this is Toman and Toman has no fractions.
            'selling_price_toman' => 'integer',
            // string, because this is a decimal and a float would lose it.
            'provider_cost_snapshot' => 'decimal:6',
        ];
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * @return BelongsTo<ProviderLocation, $this>
     */
    public function providerLocation(): BelongsTo
    {
        return $this->belongsTo(ProviderLocation::class);
    }

    /**
     * @return BelongsTo<ProviderImage, $this>
     */
    public function defaultImage(): BelongsTo
    {
        return $this->belongsTo(ProviderImage::class, 'default_image_id');
    }
}
