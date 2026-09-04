<?php

declare(strict_types=1);

namespace App\Telegram;

use App\Enums\UserStatus;
use App\Models\TelegramAccount;
use App\Models\TelegramUpdate;
use App\Telegram\Data\NormalizedUpdate;
use App\Telegram\Enums\TelegramAction;
use App\Telegram\Enums\TelegramUpdateType;
use App\Telegram\Exceptions\TelegramApiException;
use App\Telegram\Exceptions\TelegramForbidden;

/**
 * Decides what to do about one update, and does it.
 *
 * Everything it works from is already safe: the row was normalized at the
 * webhook boundary, so this reads numbers and closed-enum values rather than
 * anything a stranger typed.
 *
 * The set of things it can do is deliberately small — identify the customer,
 * show the menu, answer politely. No order is placed, no wallet is touched and
 * no provider is called from here. That is not a gap: it means the
 * deduplication machinery underneath can be proven correct while the only thing
 * a duplicate could repeat is a greeting, rather than after it can repeat a
 * purchase.
 */
final readonly class TelegramUpdateProcessor
{
    public function __construct(
        private TelegramApiClient $telegram,
        private TelegramAccountService $accounts,
        private TelegramStateStore $state,
    ) {}

    /**
     * Handle an update that has already been recorded.
     *
     * @throws TelegramForbidden When the customer has blocked the bot.
     */
    public function process(TelegramUpdate $update, NormalizedUpdate $normalized): void
    {
        // The spinner first, always, and before anything that might be slow.
        // Telegram leaves the customer's client spinning until this returns.
        if ($normalized->callbackQueryId !== null) {
            $this->acknowledge($normalized);
        }

        $account = $this->accounts->identify($normalized);

        if (! $account instanceof TelegramAccount) {
            // A channel post, or another bot. Recorded so Telegram stops
            // retrying, and otherwise none of our business.
            return;
        }

        if (! $normalized->isPrivate()) {
            // The bot is in a group. Nothing here creates a purchase context
            // from a room, and nothing replies into one.
            return;
        }

        $chatId = $account->telegram_chat_id;
        $status = $account->user->status;

        if ($status !== UserStatus::Active) {
            // Suspended or banned. Their profile metadata was refreshed above,
            // which is harmless, but the menu is not offered and nothing is
            // reactivated.
            $this->telegram->sendMessage($chatId, MainMenu::RESTRICTED);

            return;
        }

        match ($normalized->action) {
            TelegramAction::Start => $this->start($chatId, $normalized),
            TelegramAction::MainMenu => $this->menu($chatId),
            TelegramAction::MenuHelp => $this->telegram->sendMessage($chatId, MainMenu::HELP, MainMenu::keyboard()),
            TelegramAction::MenuProfile => $this->telegram->sendMessage(
                $chatId,
                MainMenu::profile((int) $account->telegram_user_id, $status),
                MainMenu::keyboard(),
            ),
            // Recognised, and honestly not ready. The sales phase fills these
            // in; until then they change nothing.
            TelegramAction::MenuBuyServer,
            TelegramAction::MenuMyServers,
            TelegramAction::MenuWallet,
            TelegramAction::MenuInvoices => $this->telegram->sendMessage(
                $chatId,
                MainMenu::notReadyFor($normalized->action),
                MainMenu::keyboard(),
            ),
            TelegramAction::Unknown => $this->unknown($chatId, $normalized),
        };
    }

    /**
     * A fresh start: forget any half-finished conversation, then greet.
     *
     * Clearing state is the point of `/start`. A customer who sends it is
     * asking to begin again, and resuming a conversation they had abandoned is
     * how somebody ends up confirming a purchase they no longer remember
     * choosing.
     */
    private function start(int $chatId, NormalizedUpdate $normalized): void
    {
        if ($normalized->telegramUserId !== null) {
            $this->state->forget($normalized->telegramUserId);
        }

        $this->telegram->sendMessage($chatId, MainMenu::welcome(), MainMenu::keyboard());
    }

    private function menu(int $chatId): void
    {
        $this->telegram->sendMessage($chatId, MainMenu::PROMPT, MainMenu::keyboard());
    }

    /**
     * Something arrived that this phase has no meaning for.
     *
     * A pressed button that no longer means anything gets a different sentence
     * from unrecognised typing, because the two feel different to a customer:
     * one is a thing they just did that did not work, the other is a
     * misunderstanding.
     */
    private function unknown(int $chatId, NormalizedUpdate $normalized): void
    {
        $message = $normalized->type === TelegramUpdateType::CallbackQuery
            ? MainMenu::CALLBACK_EXPIRED
            : MainMenu::UNKNOWN;

        $this->telegram->sendMessage($chatId, $message, MainMenu::keyboard());
    }

    /**
     * Stop the client spinner.
     *
     * Best effort, deliberately. An acknowledgement that fails must not stop
     * the update being handled — the customer would then have both a spinning
     * button and no answer.
     */
    private function acknowledge(NormalizedUpdate $normalized): void
    {
        $notice = $normalized->action === TelegramAction::Unknown
            ? MainMenu::CALLBACK_EXPIRED
            : null;

        try {
            $this->telegram->answerCallbackQuery((string) $normalized->callbackQueryId, $notice);
        } catch (TelegramForbidden) {
            // Blocked. The send below will surface it as the one 403 that
            // matters, against the chat it actually happened to.
        } catch (TelegramApiException) {
            // Telegram declined to acknowledge — usually a query that has
            // already expired. Nothing about handling the update changes.
        }
    }
}
