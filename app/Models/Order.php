<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\HasOneThrough;

class Order extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'pending';

    public const STATUS_PAID = 'paid';

    public const STATUS_FAILED = 'failed';

    public const STATUS_PROVISIONING = 'provisioning';

    public const STATUS_PROVISIONED = 'provisioned';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_EXPIRED = 'expired';

    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_PAID,
        self::STATUS_FAILED,
        self::STATUS_PROVISIONING,
        self::STATUS_PROVISIONED,
        self::STATUS_CANCELLED,
        self::STATUS_EXPIRED,
    ];

    protected $fillable = [
        'order_number',
        'user_id',
        'status',
        'billing_mode',
        'total_toman',
        'discount_toman',
        'coupon_id',
        'gateway_code',
        'paid_at',
        'provisioned_at',
        'cost_snapshot',
    ];

    protected function casts(): array
    {
        return [
            'total_toman' => 'integer',
            'discount_toman' => 'integer',
            'paid_at' => 'datetime',
            'provisioned_at' => 'datetime',
            'cost_snapshot' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function coupon(): BelongsTo
    {
        return $this->belongsTo(Coupon::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function product(): HasOneThrough
    {
        return $this->hasOneThrough(
            Product::class,
            OrderItem::class,
            'order_id',
            'id',
            'id',
            'product_id'
        );
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function server(): HasOne
    {
        return $this->hasOne(Server::class);
    }
}
