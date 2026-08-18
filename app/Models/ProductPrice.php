<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductPrice extends Model
{
    use HasFactory;

    protected $fillable = [
        'product_id',
        'billing_cycle',
        'billing_mode',
        'price_toman',
        'hourly_price_toman',
        'monthly_cap_toman',
        'provider_cost',
        'provider_currency',
        'exchange_rate',
        'local_cost',
        'gross_margin',
        'valid_from',
        'valid_to',
    ];

    protected function casts(): array
    {
        return [
            'price_toman' => 'integer',
            'hourly_price_toman' => 'integer',
            'monthly_cap_toman' => 'integer',
            'provider_cost' => 'decimal:2',
            'exchange_rate' => 'decimal:4',
            'local_cost' => 'integer',
            'gross_margin' => 'integer',
            'valid_from' => 'datetime',
            'valid_to' => 'datetime',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
