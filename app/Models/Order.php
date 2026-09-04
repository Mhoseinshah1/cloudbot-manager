<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\OrderFailureCategory;
use App\Enums\OrderStatus;
use App\Exceptions\FinancialRecordDeletionForbidden;
use App\Orders\Exceptions\OrderSnapshotIsImmutable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * One customer's purchase of one server.
 *
 * Half of this row is history and half is lifecycle. The history — who bought
 * what, for how much, at which rate, against which terms — is fixed at
 * creation: the customer was told those numbers, and an order that could be
 * repriced afterwards would be a record of nothing. The lifecycle — status,
 * attempts, the provisioning token, when it finished — is expected to move.
 *
 * Status is never assigned here. It changes through OrderStateMachine, which
 * compares and sets so that two workers acting at once cannot both believe they
 * won. Writing `$order->status = ...` would skip that, which is why the model
 * refuses it.
 *
 * @property int $user_id
 * @property int $product_id
 * @property int $product_location_price_id
 * @property string $order_number
 * @property OrderStatus $status
 * @property int $total_toman
 * @property string $idempotency_key
 * @property \Illuminate\Support\Carbon|null $awaiting_payment_expires_at
 * @property string|null $provisioning_uuid
 * @property OrderFailureCategory|null $failure_category
 * @property string|null $failure_reason
 * @property int $attempts
 * @property array<string, mixed> $cost_snapshot
 * @property array<string, mixed> $pricing_snapshot
 * @property string $aup_version
 * @property \Illuminate\Support\Carbon $aup_accepted_at
 * @property \Illuminate\Support\Carbon|null $provisioned_at
 * @property-read User $user
 * @property-read Product $product
 * @property-read ProductLocationPrice $productLocationPrice
 * @property-read Server|null $server
 */
class Order extends Model
{
    /** @use HasFactory<\Database\Factories\OrderFactory> */
    use HasFactory;

    /**
     * What creation may set. Status is absent on purpose: it is the state
     * machine's to write, and the database default starts it at pending.
     *
     * @var list<string>
     */
    protected $fillable = [
        'user_id', 'product_id', 'product_location_price_id', 'order_number',
        'total_toman', 'idempotency_key', 'cost_snapshot', 'pricing_snapshot',
        'aup_version', 'aup_accepted_at',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => OrderStatus::Pending->value,
        'attempts' => 0,
    ];

    /**
     * Fixed at creation. The database enforces this too.
     *
     * @var list<string>
     */
    public const IMMUTABLE = [
        'user_id', 'product_id', 'product_location_price_id', 'order_number',
        'total_toman', 'idempotency_key', 'cost_snapshot', 'pricing_snapshot',
        'aup_version', 'aup_accepted_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => OrderStatus::class,
            'failure_category' => OrderFailureCategory::class,
            // Whole Toman. An int, never a float.
            'total_toman' => 'integer',
            'attempts' => 'integer',
            'awaiting_payment_expires_at' => 'datetime',
            'aup_accepted_at' => 'datetime',
            'provisioned_at' => 'datetime',
            'cost_snapshot' => 'array',
            'pricing_snapshot' => 'array',
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
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * @return BelongsTo<ProductLocationPrice, $this>
     */
    public function productLocationPrice(): BelongsTo
    {
        return $this->belongsTo(ProductLocationPrice::class);
    }

    /**
     * @return HasMany<Invoice, $this>
     */
    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    /**
     * @return HasMany<ProvisioningAttempt, $this>
     */
    public function provisioningAttempts(): HasMany
    {
        return $this->hasMany(ProvisioningAttempt::class);
    }

    /**
     * The one server this order bought, once it exists.
     *
     * @return HasOne<Server, $this>
     */
    public function server(): HasOne
    {
        return $this->hasOne(Server::class);
    }

    /**
     * The key under which this order's wallet debit is recorded.
     *
     * Derived from the order's own identity, so a replayed payment resolves to
     * the ledger entry that already exists instead of charging twice.
     */
    public function paymentIdempotencyKey(): string
    {
        return 'order:'.$this->getKey().':payment';
    }

    /**
     * The key under which this order's refund is recorded.
     *
     * Fixed by the specification. It is what makes a refund happen once even if
     * the decision to refund is reached twice.
     */
    public function refundIdempotencyKey(): string
    {
        return 'refund:order:'.$this->getKey();
    }

    /** Whether this order's awaiting-payment window has closed. */
    public function paymentWindowHasClosed(): bool
    {
        return $this->awaiting_payment_expires_at !== null
            && $this->awaiting_payment_expires_at->isPast();
    }

    /**
     * Refuse the two things that must never happen to an order.
     *
     * Deletion, because an order is financial evidence; and any edit to what
     * the customer was quoted, because that is a record of something already
     * said. Both are refused by the database as well, for code that never
     * loads this model.
     */
    protected static function booted(): void
    {
        static::deleting(static function (self $order): never {
            throw FinancialRecordDeletionForbidden::forOrder();
        });

        static::updating(static function (self $order): void {
            foreach (self::IMMUTABLE as $attribute) {
                if ($order->isDirty($attribute)) {
                    throw OrderSnapshotIsImmutable::cannotChange($attribute);
                }
            }
        });
    }
}
