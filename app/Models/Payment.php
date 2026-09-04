<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PaymentStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An attempt to put money into a wallet.
 *
 * A pending payment is a claim, not money. Nothing is credited until it settles.
 *
 * @property int $user_id
 * @property string $gateway
 * @property int $amount_toman
 * @property int $gateway_fee_toman
 * @property PaymentStatus $status
 * @property string|null $provider_payment_id
 * @property string $idempotency_key
 * @property int|null $verified_by_admin_id
 * @property \Illuminate\Support\Carbon|null $paid_at
 * @property array<string, mixed>|null $request_metadata
 * @property array<string, mixed>|null $verification_metadata
 */
class Payment extends Model
{
    /** @use HasFactory<\Database\Factories\PaymentFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id', 'order_id', 'gateway', 'amount_toman', 'gateway_fee_toman',
        'status', 'provider_payment_id', 'idempotency_key', 'verified_by_admin_id',
        'receipt_path', 'expires_at', 'paid_at', 'request_metadata', 'verification_metadata',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'gateway_fee_toman' => 0,
        'status' => PaymentStatus::Pending->value,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount_toman' => 'integer',
            'gateway_fee_toman' => 'integer',
            'status' => PaymentStatus::class,
            'expires_at' => 'datetime',
            'paid_at' => 'datetime',
            'request_metadata' => 'array',
            'verification_metadata' => 'array',
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
     * @return BelongsTo<User, $this>
     */
    public function verifiedByAdmin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by_admin_id');
    }

    /**
     * The key under which this payment's wallet credit is recorded.
     *
     * Derived from the payment's own identity, so a replayed settlement
     * resolves to the ledger entry that already exists instead of writing a
     * second one.
     */
    public function settlementIdempotencyKey(): string
    {
        return 'payment:'.$this->getKey().':settlement';
    }
}
