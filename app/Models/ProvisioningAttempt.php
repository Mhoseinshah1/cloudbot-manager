<?php

declare(strict_types=1);

namespace App\Models;

use App\Cloud\Enums\ProviderErrorCategory;
use App\Enums\ProvisioningOutcome;
use App\Enums\ProvisioningStage;
use App\Exceptions\FinancialRecordDeletionForbidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One call to a provider, and what came back.
 *
 * History rather than state. An attempt records what was true when the call
 * returned, and a later reconciliation that learns more writes its own row
 * instead of editing this one — an attempt that timed out really did time out,
 * and rewriting it into a success would destroy the only evidence of why a
 * customer waited.
 *
 * @property int $order_id
 * @property string $provisioning_uuid
 * @property int $attempt_no
 * @property ProvisioningStage $stage
 * @property ProvisioningOutcome $outcome
 * @property ProviderErrorCategory|null $error_category
 * @property \Illuminate\Support\Carbon $started_at
 * @property \Illuminate\Support\Carbon|null $finished_at
 * @property array<string, mixed> $request_summary
 * @property array<string, mixed>|null $result_summary
 * @property-read Order $order
 */
class ProvisioningAttempt extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'order_id', 'provisioning_uuid', 'attempt_no', 'stage', 'outcome',
        'error_category', 'started_at', 'finished_at', 'request_summary', 'result_summary',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'stage' => ProvisioningStage::class,
            'outcome' => ProvisioningOutcome::class,
            'error_category' => ProviderErrorCategory::class,
            'attempt_no' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'request_summary' => 'array',
            'result_summary' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * Refuse deletion. The database refuses it too.
     *
     * Retained by the specification, and for a concrete reason: this is the
     * evidence for whether a customer was refunded, and evidence that can be
     * deleted is not evidence.
     */
    protected static function booted(): void
    {
        static::deleting(static function (self $attempt): never {
            throw FinancialRecordDeletionForbidden::forProvisioningAttempt();
        });
    }
}
