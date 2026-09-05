<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Support\Queues;
use App\Telegram\Exceptions\TelegramApiException;
use App\Telegram\TelegramApiClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Removes a message from a customer's chat after a short while.
 *
 * Written for exactly one use: the root password. A credential on somebody's
 * phone screen should not stay there, and shortening that window is worth
 * doing — but it is a courtesy and never a control. Telegram may refuse, the
 * customer may have forwarded it, screenshotted it, or simply read it. Nothing
 * about the system's security may depend on this succeeding, which is why the
 * message tells them to change the password rather than relying on deletion.
 *
 * The payload is a chat id and a message id. Not the password: a job payload is
 * serialized into Redis, read by anything that can reach it, and printed whole
 * in a failed-job record. Deleting a message does not require knowing what was
 * in it, so this never learns.
 */
final class DeleteTelegramMessageJob implements ShouldQueue
{
    use Queueable;

    /**
     * One attempt, deliberately.
     *
     * A message that could not be deleted is a message that is already out.
     * Retrying achieves nothing a customer can rely on, and a queue full of
     * retries would be noise around a problem that is not fixable this way.
     */
    public int $tries = 1;

    public function __construct(
        public readonly int $chatId,
        public readonly int $messageId,
    ) {
        $this->onQueue(Queues::Notifications->value);
    }

    public function handle(TelegramApiClient $telegram): void
    {
        try {
            $telegram->deleteMessage($this->chatId, $this->messageId);
        } catch (TelegramApiException) {
            // Too old to delete, already gone, or the customer blocked the bot.
            // None of those is a failure worth retrying, and none of them
            // changes the advice the message itself gave.
        }
    }

    /** The queue this job must run on, for tests and topology checks. */
    public static function queueName(): string
    {
        return Queues::Notifications->value;
    }
}
