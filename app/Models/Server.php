<?php

namespace App\Models;

use App\Enums\BillingMode;
use App\Enums\BillingState;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int|null $user_id
 * @property int|null $order_id
 * @property int|null $product_id
 * @property int|null $provider_id
 * @property string|null $provider_server_id
 * @property string|null $name
 * @property string|null $ip_address
 * @property int|null $provider_location_id
 * @property int|null $hourly_rate_toman
 * @property int|null $monthly_cap_toman
 * @property int|null $current_period_charged
 * @property string|null $billing_mode
 * @property string|null $billing_state
 * @property string|null $power_state
 * @property string|null $status
 * @property Carbon|null $billing_started_at
 * @property Carbon|null $last_billed_at
 * @property Carbon|null $billing_stopped_at
 * @property Carbon|null $billing_period_started_at
 * @property Carbon|null $billing_period_ends_at
 * @property Carbon|null $billing_state_changed_at
 * @property Carbon|null $grace_started_at
 * @property Carbon|null $grace_ends_at
 * @property Carbon|null $lifecycle_action_performed_at
 * @property Carbon|null $expires_at
 * @property Carbon|null $suspended_at
 */
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
        'provider_metadata',
        'status',
        'power_state',
        'billing_mode',
        'provider_cost',
        'provider_currency',
        'exchange_rate',
        'local_cost',
        'selling_price',
        'gross_margin',
        'hourly_rate_toman',
        'monthly_cap_toman',
        'billing_started_at',
        'last_billed_at',
        'billing_stopped_at',
        'billing_period_started_at',
        'billing_period_ends_at',
        'current_period_charged',
        'billing_state',
        'billing_state_changed_at',
        'grace_started_at',
        'grace_ends_at',
        'lifecycle_action_performed_at',
        'expires_at',
        'suspended_at',
    ];

    protected function casts(): array
    {
        return [
            'plan_snapshot' => 'array',
            'image_snapshot' => 'array',
            'provider_metadata' => 'array',
            'provider_cost' => 'decimal:2',
            'exchange_rate' => 'decimal:4',
            'local_cost' => 'integer',
            'selling_price' => 'integer',
            'gross_margin' => 'integer',
            'hourly_rate_toman' => 'integer',
            'monthly_cap_toman' => 'integer',
            'billing_started_at' => 'datetime',
            'last_billed_at' => 'datetime',
            'billing_stopped_at' => 'datetime',
            'billing_period_started_at' => 'datetime',
            'billing_period_ends_at' => 'datetime',
            'current_period_charged' => 'integer',
            'billing_state_changed_at' => 'datetime',
            'grace_started_at' => 'datetime',
            'grace_ends_at' => 'datetime',
            'lifecycle_action_performed_at' => 'datetime',
            'root_password_encrypted' => 'encrypted',
            'expires_at' => 'datetime',
            'suspended_at' => 'datetime',
        ];
    }

    public function storeRootPassword(string $password): void
    {
        $this->forceFill(['root_password_encrypted' => $password])->save();
    }

    public function isHourlyBilling(): bool
    {
        return BillingMode::tryFrom((string) $this->billing_mode)?->isHourly() ?? false;
    }

    public function isHourlyCappedBilling(): bool
    {
        return BillingMode::tryFrom((string) $this->billing_mode) === BillingMode::HourlyCapped;
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

    public function subscription(): HasOne
    {
        return $this->hasOne(Subscription::class)->latestOfMany();
    }

    public function billingPeriods(): HasMany
    {
        return $this->hasMany(ServerBillingPeriod::class);
    }

    public function lowBalanceWarnings(): HasMany
    {
        return $this->hasMany(LowBalanceWarning::class);
    }

    public function billingState(): BillingState
    {
        return BillingState::tryFrom((string) $this->billing_state) ?? BillingState::Active;
    }
}
