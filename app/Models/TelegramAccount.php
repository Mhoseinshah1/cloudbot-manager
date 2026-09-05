<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A Telegram identity belonging to a user.
 *
 * @property int $id
 * @property int $user_id
 * @property int $telegram_user_id
 * @property int $telegram_chat_id
 * @property string|null $username
 * @property \Illuminate\Support\Carbon|null $bot_blocked_at
 * @property \Illuminate\Support\Carbon|null $last_seen_at
 */
class TelegramAccount extends Model
{
    /** @use HasFactory<\Database\Factories\TelegramAccountFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'telegram_user_id',
        'telegram_chat_id',
        'username',
        'first_name',
        'last_name',
        'bot_blocked_at',
        'last_seen_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'telegram_user_id' => 'integer',
            'telegram_chat_id' => 'integer',
            'bot_blocked_at' => 'datetime',
            'last_seen_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function hasBlockedBot(): bool
    {
        return $this->bot_blocked_at !== null;
    }
}
