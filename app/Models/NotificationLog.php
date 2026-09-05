<?php

declare(strict_types=1);

namespace App\Models;

use App\Cloud\Enums\ProviderErrorCategory;
use App\Exceptions\FinancialRecordDeletionForbidden;
use App\Notifications\NotificationChannel;
use App\Notifications\NotificationStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A record that we tried to tell somebody something.
 *
 * Written whatever the outcome. A refusal is as worth keeping as a success —
 * "the customer blocked us" is the answer to a support question, and a table
 * that only recorded what worked could never give it.
 *
 * The summary holds identifiers and names, never the message that was sent. A
 * rendered message is where a credential could plausibly appear, and this table
 * is read casually.
 *
 * @property int|null $user_id
 * @property int|null $outbox_message_id
 * @property NotificationChannel $channel
 * @property string $type
 * @property NotificationStatus $status
 * @property string|null $deduplication_key
 * @property array<string, mixed>|null $summary
 * @property \Illuminate\Support\Carbon|null $sent_at
 * @property ProviderErrorCategory|null $error_category
 */
class NotificationLog extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id', 'outbox_message_id', 'channel', 'type', 'status',
        'deduplication_key', 'summary', 'sent_at', 'error_category',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'channel' => NotificationChannel::class,
            'status' => NotificationStatus::class,
            'error_category' => ProviderErrorCategory::class,
            'summary' => 'array',
            'sent_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    protected static function booted(): void
    {
        static::deleting(static function (self $log): never {
            throw FinancialRecordDeletionForbidden::forNotificationLog();
        });
    }
}
