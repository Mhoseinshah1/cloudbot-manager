<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\WalletTransactionType;
use App\Exceptions\WalletLedgerIsImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One movement of customer money.
 *
 * Write through WalletService and nowhere else. Constructing these directly
 * would skip the row lock that makes the balance correct, and skip the
 * before/after arithmetic that makes the ledger auditable.
 *
 * @property int $user_id
 * @property WalletTransactionType $type
 * @property int $amount_toman
 * @property int $balance_before_toman
 * @property int $balance_after_toman
 * @property string $idempotency_key
 * @property string|null $reference_type
 * @property string|null $reference_id
 * @property string $description
 * @property array<string, mixed>|null $metadata
 * @property \Illuminate\Support\Carbon $created_at
 */
class WalletTransaction extends Model
{
    /** Written once, never touched again. */
    public const UPDATED_AT = null;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id', 'type', 'amount_toman', 'balance_before_toman', 'balance_after_toman',
        'idempotency_key', 'reference_type', 'reference_id', 'description', 'metadata',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => WalletTransactionType::class,
            // Integers, never floats. Money in this system is whole Toman.
            'amount_toman' => 'integer',
            'balance_before_toman' => 'integer',
            'balance_after_toman' => 'integer',
            'metadata' => 'array',
            'created_at' => 'datetime',
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
     * Refuse mutation in the application layer.
     *
     * The database refuses it too. This one gives a clear error at the call
     * site; that one still holds for code that never loads this model.
     */
    protected static function booted(): void
    {
        static::updating(static function (self $transaction): never {
            throw WalletLedgerIsImmutable::cannotUpdate();
        });

        static::deleting(static function (self $transaction): never {
            throw WalletLedgerIsImmutable::cannotDelete();
        });
    }
}
