<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\BillingMode;
use App\Enums\ServerPowerState;
use App\Enums\ServerStatus;
use App\Exceptions\FinancialRecordDeletionForbidden;
use App\Provisioning\Exceptions\ServerIdentityIsImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A server this business sold and owes service on.
 *
 * Two halves, kept apart on purpose. Which machine this is, whose it is, which
 * order bought it and what the economics were are decided once and frozen —
 * by this model and by a trigger, because reconciliation writes to this table
 * constantly and a provider answering oddly must never be able to move a
 * machine between customers or restate what it cost.
 *
 * The rest — address, power, status, whitelisted metadata — is expected to be
 * corrected from the provider, which is the entire job of the inventory sweep.
 *
 * The root password, where a provider issues one at all, lives in its own
 * encrypted column and is hidden from serialization. It is never copied into
 * metadata, a snapshot, a log, an audit entry or an outbox payload.
 *
 * @property int $user_id
 * @property int $order_id
 * @property int $product_id
 * @property int $provider_id
 * @property int $provider_location_id
 * @property string $provider_server_id
 * @property string $provisioning_uuid
 * @property string $name
 * @property string|null $hostname
 * @property string|null $ip_address
 * @property string|null $ipv6_address
 * @property string|null $datacenter
 * @property array<string, mixed> $plan_snapshot
 * @property array<string, mixed> $image_snapshot
 * @property array<string, mixed>|null $provider_metadata
 * @property ServerStatus $status
 * @property ServerPowerState $power_state
 * @property BillingMode $billing_mode
 * @property string $provider_cost
 * @property string $provider_currency
 * @property string $exchange_rate
 * @property string $local_cost_toman
 * @property int $selling_price_toman
 * @property string $gross_margin_toman
 * @property string|null $root_password_encrypted
 * @property \Illuminate\Support\Carbon|null $suspended_at
 * @property \Illuminate\Support\Carbon|null $terminated_at
 * @property-read User $user
 * @property-read Order $order
 * @property-read Provider $provider
 * @property-read Subscription|null $subscription
 */
class Server extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id', 'order_id', 'product_id', 'provider_id', 'provider_location_id',
        'provider_server_id', 'provisioning_uuid', 'name', 'hostname', 'ip_address',
        'ipv6_address', 'datacenter', 'plan_snapshot', 'image_snapshot', 'provider_metadata',
        'status', 'power_state', 'billing_mode', 'provider_cost', 'provider_currency',
        'exchange_rate', 'local_cost_toman', 'selling_price_toman', 'gross_margin_toman',
        'root_password_encrypted',
    ];

    /**
     * Decided at creation. The database enforces this too.
     *
     * @var list<string>
     */
    public const IMMUTABLE = [
        'user_id', 'order_id', 'product_id', 'provider_id', 'provider_server_id',
        'provisioning_uuid', 'provider_location_id', 'plan_snapshot', 'image_snapshot',
        'billing_mode', 'provider_cost', 'provider_currency', 'exchange_rate',
        'local_cost_toman', 'selling_price_toman', 'gross_margin_toman',
    ];

    /**
     * What a provider is allowed to correct.
     *
     * Named as a list rather than left implicit, so that widening it is a
     * visible edit somebody reviews rather than a field quietly appearing in a
     * synchronization loop.
     *
     * @var list<string>
     */
    public const PROVIDER_SYNCHRONIZED = [
        'status', 'power_state', 'hostname', 'ip_address', 'ipv6_address',
        'datacenter', 'provider_metadata',
    ];

    /**
     * Never serialized. A password that appears in a JSON response has already
     * leaked, however carefully the response was going to be used.
     *
     * @var list<string>
     */
    protected $hidden = ['root_password_encrypted'];

    /**
     * @return array<string, string>
     *
     * provider_cost, exchange_rate, local_cost_toman and gross_margin_toman are
     * deliberately absent. PostgreSQL returns NUMERIC as a string, and every
     * cast PHP offers for them is a float — these are money, and the exactness
     * is why they are NUMERIC in the first place.
     */
    protected function casts(): array
    {
        return [
            'status' => ServerStatus::class,
            'power_state' => ServerPowerState::class,
            'billing_mode' => BillingMode::class,
            'plan_snapshot' => 'array',
            'image_snapshot' => 'array',
            'provider_metadata' => 'array',
            // Whole Toman. An int, never a float.
            'selling_price_toman' => 'integer',
            // Encrypted at rest by Laravel. The column holds ciphertext; a
            // database dump, a backup and a stray SELECT all show nothing.
            'root_password_encrypted' => 'encrypted',
            'suspended_at' => 'datetime',
            'terminated_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * @return BelongsTo<Provider, $this>
     */
    public function provider(): BelongsTo
    {
        return $this->belongsTo(Provider::class);
    }

    /**
     * @return HasOne<Subscription, $this>
     */
    public function subscription(): HasOne
    {
        return $this->hasOne(Subscription::class);
    }

    /**
     * Refuse the two things that must never happen to a server record.
     *
     * Deletion, because the row is the evidence behind every invoice raised
     * against the machine; and any edit to which machine it is or what it cost,
     * because those are answers a provider is not entitled to change.
     */
    protected static function booted(): void
    {
        static::deleting(static function (self $server): never {
            throw FinancialRecordDeletionForbidden::forServer();
        });

        static::updating(static function (self $server): void {
            foreach (self::IMMUTABLE as $attribute) {
                if ($server->isDirty($attribute)) {
                    throw ServerIdentityIsImmutable::cannotChange($attribute);
                }
            }
        });
    }
}
