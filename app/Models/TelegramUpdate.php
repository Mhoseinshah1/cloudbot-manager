<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\TelegramUpdateStatus;
use App\Telegram\Enums\TelegramAction;
use App\Telegram\Enums\TelegramChatType;
use App\Telegram\Enums\TelegramUpdateType;
use Illuminate\Database\Eloquent\Model;

/**
 * One delivery from Telegram, recorded so it is only ever acted on once.
 *
 * The row is written before any handler runs, which is the whole point: an
 * update that is recorded and then fails is retryable, while an update that is
 * acted on and then recorded can be acted on twice.
 *
 * Nothing here is the customer's own text. Every stored field is a value this
 * system chose from a closed vocabulary.
 *
 * @property int $update_id
 * @property TelegramUpdateType $type
 * @property TelegramChatType $chat_type
 * @property int|null $telegram_user_id
 * @property int|null $telegram_chat_id
 * @property int|null $message_id
 * @property string|null $callback_query_id
 * @property TelegramAction $action
 * @property TelegramUpdateStatus $status
 * @property \Illuminate\Support\Carbon $received_at
 * @property \Illuminate\Support\Carbon|null $processed_at
 * @property string|null $failure_reason
 * @property array<string, mixed>|null $metadata
 */
class TelegramUpdate extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'update_id', 'type', 'chat_type', 'telegram_user_id', 'telegram_chat_id',
        'message_id', 'callback_query_id', 'action', 'status', 'received_at',
        'processed_at', 'failure_reason', 'metadata',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => TelegramUpdateStatus::Received->value,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => TelegramUpdateType::class,
            'chat_type' => TelegramChatType::class,
            'action' => TelegramAction::class,
            'status' => TelegramUpdateStatus::class,
            // Telegram ids exceed 32 bits; PHP integers on a 64-bit build hold
            // them exactly.
            'update_id' => 'integer',
            'telegram_user_id' => 'integer',
            'telegram_chat_id' => 'integer',
            'message_id' => 'integer',
            'received_at' => 'datetime',
            'processed_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    /** Whether this update still has work owing. */
    public function isPending(): bool
    {
        return $this->status->isPending();
    }

    /**
     * The customer's Telegram identity, if this update carried one.
     *
     * Looked up by `telegram_user_id` and never by username, because a username
     * can be released and taken by somebody else.
     */
    public function account(): ?TelegramAccount
    {
        if ($this->telegram_user_id === null) {
            return null;
        }

        $account = TelegramAccount::query()
            ->where('telegram_user_id', $this->telegram_user_id)
            ->first();

        return $account instanceof TelegramAccount ? $account : null;
    }
}
