<?php

declare(strict_types=1);

namespace App\Telegram;

use App\Enums\TelegramUpdateStatus;
use App\Models\TelegramUpdate;
use App\Telegram\Data\NormalizedUpdate;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Writes down that an update arrived, exactly once.
 *
 * Telegram re-delivers. Any webhook that is slow, times out, or answers
 * anything other than 200 gets the same update again, carrying the same
 * `update_id` — so without a durable record of what has already arrived, one
 * tap of a button becomes two of whatever that button does.
 *
 * The unique index is the authority, not a lookup. Checking whether a row
 * exists and then inserting leaves a window between the two statements, and two
 * concurrent retries fit inside it comfortably; here the insert is simply
 * attempted, and losing the race means reading back the winner's row.
 */
final readonly class TelegramUpdateRecorder
{
    /**
     * Record this update, or return the record that already exists.
     *
     * @return array{update: TelegramUpdate, isNew: bool}
     */
    public function record(NormalizedUpdate $normalized): array
    {
        try {
            // In its own transaction: PostgreSQL aborts a transaction outright
            // on a constraint error, so without a savepoint the recovery read
            // below could not even run.
            $update = DB::transaction(fn (): TelegramUpdate => TelegramUpdate::query()->create([
                'update_id' => $normalized->updateId,
                'type' => $normalized->type->value,
                'chat_type' => $normalized->chatType->value,
                'telegram_user_id' => $normalized->telegramUserId,
                'telegram_chat_id' => $normalized->telegramChatId,
                'message_id' => $normalized->messageId,
                'callback_query_id' => $normalized->callbackQueryId,
                'action' => $normalized->action->value,
                'status' => TelegramUpdateStatus::Received->value,
                'received_at' => CarbonImmutable::now(),
                'metadata' => $normalized->metadata(),
            ]));

            return ['update' => $update, 'isNew' => true];
        } catch (QueryException $exception) {
            $existing = $this->find($normalized->updateId);

            if ($existing instanceof TelegramUpdate) {
                // The losing side of the race, which is the normal path for a
                // Telegram retry.
                return ['update' => $existing, 'isNew' => false];
            }

            throw $exception;
        }
    }

    public function find(int $updateId): ?TelegramUpdate
    {
        $update = TelegramUpdate::query()->where('update_id', $updateId)->first();

        return $update instanceof TelegramUpdate ? $update : null;
    }

    /**
     * Mark an update finished, once.
     *
     * Compare-and-set on the status, so two workers that both handled the same
     * update cannot both report having been the one that did — and the caller
     * learns which it was rather than assuming.
     */
    public function markProcessed(TelegramUpdate $update): bool
    {
        $affected = TelegramUpdate::query()
            ->whereKey($update->getKey())
            ->where('status', '!=', TelegramUpdateStatus::Processed->value)
            ->update([
                'status' => TelegramUpdateStatus::Processed->value,
                'processed_at' => CarbonImmutable::now(),
                'failure_reason' => null,
                'updated_at' => now(),
            ]);

        return $affected === 1;
    }

    /**
     * Record that handling failed, keeping the row.
     *
     * The row is never deleted to make a retry possible: deleting it would
     * discard the deduplication record, and the retry could then run whatever
     * had already half-happened a second time.
     *
     * The reason is a short normalized phrase chosen by the caller, never a
     * raw exception message — those quote back what was sent, and what is sent
     * to Telegram includes the bot token.
     */
    public function markFailed(TelegramUpdate $update, string $reason): void
    {
        TelegramUpdate::query()
            ->whereKey($update->getKey())
            ->where('status', '!=', TelegramUpdateStatus::Processed->value)
            ->update([
                'status' => TelegramUpdateStatus::Failed->value,
                'failure_reason' => mb_substr($reason, 0, 200),
                'updated_at' => now(),
            ]);
    }
}
