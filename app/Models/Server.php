<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Server extends Model
{
    use HasFactory, SoftDeletes;

    public const STATUS_PENDING = 'pending';

    public const STATUS_PROVISIONING = 'provisioning';

    public const STATUS_RUNNING = 'running';

    public const STATUS_OFF = 'off';

    public const STATUS_SUSPENDED = 'suspended';

    public const STATUS_REBUILDING = 'rebuilding';

    public const STATUS_DELETING = 'deleting';

    public const STATUS_DELETED = 'deleted';

    public const STATUS_ERROR = 'error';

    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_PROVISIONING,
        self::STATUS_RUNNING,
        self::STATUS_OFF,
        self::STATUS_SUSPENDED,
        self::STATUS_REBUILDING,
        self::STATUS_DELETING,
        self::STATUS_DELETED,
        self::STATUS_ERROR,
    ];

    protected $fillable = [
        'user_id',
        'order_id',
        'product_id',
        'provider_id',
        'provider_server_id',
        'name',
        'ip_address',
        'provider_location_id',
        'plan_snapshot',
        'image_snapshot',
        'status',
        'power_state',
        'provider_cost',
        'provider_currency',
        'exchange_rate',
        'local_cost',
        'selling_price',
        'gross_margin',
        'root_password_encrypted',
        'expires_at',
        'suspended_at',
    ];

    protected function casts(): array
    {
        return [
            'plan_snapshot' => 'array',
            'image_snapshot' => 'array',
            'provider_cost' => 'decimal:2',
            'exchange_rate' => 'decimal:4',
            'local_cost' => 'integer',
            'selling_price' => 'integer',
            'gross_margin' => 'integer',
            // Stored encrypted at rest; never logged or exposed.
            'root_password_encrypted' => 'encrypted',
            'expires_at' => 'datetime',
            'suspended_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(Provider::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(ProviderLocation::class, 'provider_location_id');
    }

    public function actions(): HasMany
    {
        return $this->hasMany(ServerAction::class);
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }
}
