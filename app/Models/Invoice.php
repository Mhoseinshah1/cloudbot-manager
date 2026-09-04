<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\InvoiceStatus;
use App\Enums\InvoiceType;
use App\Exceptions\FinancialRecordDeletionForbidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A record of what a customer was charged and for what.
 *
 * @property int $user_id
 * @property string $number
 * @property InvoiceType $type
 * @property int $amount_toman
 * @property InvoiceStatus $status
 * @property \Illuminate\Support\Carbon $issued_at
 * @property array<int, array<string, mixed>> $line_items
 * @property array<string, mixed>|null $pricing_snapshot
 */
class Invoice extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id', 'order_id', 'number', 'type', 'amount_toman',
        'status', 'issued_at', 'line_items', 'pricing_snapshot',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => InvoiceType::class,
            'amount_toman' => 'integer',
            'status' => InvoiceStatus::class,
            'issued_at' => 'datetime',
            'line_items' => 'array',
            'pricing_snapshot' => 'array',
        ];
    }

    /**
     * Refuse deletion in the application layer.
     *
     * Updates stay allowed: an invoice's status has a lifecycle ahead of it.
     * Only erasure is refused, and the database refuses it as well.
     */
    protected static function booted(): void
    {
        static::deleting(static function (self $invoice): never {
            throw FinancialRecordDeletionForbidden::forInvoice();
        });
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * What the line items add up to.
     *
     * Integer arithmetic throughout; this must equal amount_toman.
     */
    public function lineItemTotal(): int
    {
        $total = 0;

        foreach ($this->line_items as $line) {
            $total += (int) ($line['total_toman'] ?? 0);
        }

        return $total;
    }
}
