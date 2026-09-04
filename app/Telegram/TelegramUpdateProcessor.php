<?php

declare(strict_types=1);

namespace App\Telegram;

use App\Enums\ServerActionType;
use App\Enums\UserStatus;
use App\Models\TelegramAccount;
use App\Models\TelegramUpdate;
use App\Telegram\Data\NormalizedUpdate;
use App\Telegram\Enums\TelegramAction;
use App\Telegram\Enums\TelegramUpdateType;
use App\Telegram\Exceptions\TelegramApiException;
use App\Telegram\Exceptions\TelegramForbidden;
use App\Telegram\Flows\BuyServerFlow;
use App\Telegram\Flows\FlowContext;
use App\Telegram\Flows\FlowState;
use App\Telegram\Flows\InvoiceFlow;
use App\Telegram\Flows\ServerManagementFlow;
use App\Telegram\Flows\ServerMessages;
use App\Telegram\Flows\WalletFlow;

/**
 * Decides what to do about one update, and does it.
 *
 * Everything it works from is already safe: the row was normalized at the
 * webhook boundary, so this reads numbers and closed-enum values rather than
 * anything a stranger typed.
 *
 * It now sells and manages servers, and one rule survives that unchanged: no
 * provider is ever called from here. This runs on the interactive worker, where
 * a customer is waiting; a create, a reboot or a delete can block for minutes,
 * and a tap that sat inside somebody else's network timeout would make the bot
 * feel broken for everybody. Every operation that reaches a provider is written
 * down and performed on the worker built for waiting.
 *
 * Money does move here, and that is what the machinery underneath was built
 * for. A duplicate delivery could once only repeat a greeting; now it could
 * repeat a purchase, so it does not: the purchase intent generated when a buy
 * flow begins becomes the order's idempotency key, and PostgreSQL — not this
 * code — turns however many deliveries arrive into one order.
 */
final readonly class TelegramUpdateProcessor
{
    public function __construct(
        private TelegramApiClient $telegram,
        private TelegramAccountService $accounts,
        private TelegramStateStore $state,
        private FlowState $flows,
        private BuyServerFlow $buying,
        private WalletFlow $wallet,
        private InvoiceFlow $invoices,
        private ServerManagementFlow $servers,
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

        $context = new FlowContext(
            customer: $account->user,
            chatId: $chatId,
            // State is keyed by the numeric Telegram id, never by a username:
            // a username can be released and taken by somebody else.
            telegramUserId: (int) $account->telegram_user_id,
            parameters: $normalized->parameters,
        );

        match (true) {
            $normalized->action === TelegramAction::Start => $this->start($chatId, $normalized),
            $normalized->action === TelegramAction::MainMenu => $this->menu($chatId),
            $normalized->action === TelegramAction::MenuHelp => $this->telegram->sendMessage($chatId, MainMenu::HELP, MainMenu::keyboard()),
            $normalized->action === TelegramAction::MenuProfile => $this->telegram->sendMessage(
                $chatId,
                MainMenu::profile((int) $account->telegram_user_id, $status),
                MainMenu::keyboard(),
            ),
            $normalized->action === TelegramAction::MenuBuyServer => $this->buying->start($context),
            $normalized->action === TelegramAction::MenuMyServers => $this->servers->list($context),
            $normalized->action === TelegramAction::MenuWallet => $this->wallet->show($context),
            $normalized->action === TelegramAction::MenuInvoices => $this->invoices->list($context),
            $normalized->action->isBuyStep() => $this->buyStep($context, $normalized),
            default => $this->manage($context, $update, $normalized),
        };
    }

    /**
     * A step inside the buy flow.
     *
     * Every one of these needs a live flow whose token matches the button. A
     * Telegram keyboard stays on a customer's screen forever, so a tap from a
     * previous run would otherwise act on whatever the conversation has become
     * since — ordering a server they chose in a different conversation. A token
     * that does not match means the customer is told the flow expired and sent
     * back to the menu, having changed nothing.
     */
    private function buyStep(FlowContext $context, NormalizedUpdate $normalized): void
    {
        $state = $this->flows->matching(
            $context->telegramUserId,
            FlowState::BUY_SERVER,
            $context->flowToken(),
        );

        if ($state === null) {
            $this->telegram->sendMessage($context->chatId, MainMenu::STATE_EXPIRED, MainMenu::keyboard());

            return;
        }

        match ($normalized->action) {
            TelegramAction::BuyPage => $this->buying->page($context, $state, $context->page()),
            TelegramAction::BuyProduct => $this->buying->chooseProduct($context, $state, (int) $context->id()),
            TelegramAction::BuyLocation => $this->buying->chooseLocation($context, $state, (int) $context->id()),
            TelegramAction::BuyImage => $this->buying->chooseImage($context, $state),
            TelegramAction::BuyAcceptTerms => $this->buying->acceptTerms($context, $state),
            TelegramAction::BuyConfirm => $this->buying->confirm($context, $state),
            TelegramAction::BuyCancel => $this->buying->cancel($context, $state),
            default => $this->unknown($context->chatId, $normalized),
        };
    }

    /**
     * Servers, wallet pages and invoices.
     *
     * The ids here come from buttons and are treated as such: every lookup is
     * scoped by customer in the query, so one naming somebody else's server
     * finds nothing.
     */
    private function manage(FlowContext $context, TelegramUpdate $update, NormalizedUpdate $normalized): void
    {
        $id = $context->id();

        match ($normalized->action) {
            TelegramAction::ServerPage => $this->servers->list($context, $context->page()),
            TelegramAction::ServerView => $this->servers->view($context, (int) $id),
            TelegramAction::ServerPowerOn => $this->servers->requestAction(
                $context, (int) $id, ServerActionType::PowerOn, $update->update_id,
            ),
            TelegramAction::ServerPowerOff => $this->servers->requestAction(
                $context, (int) $id, ServerActionType::PowerOff, $update->update_id,
            ),
            TelegramAction::ServerReboot => $this->servers->requestAction(
                $context, (int) $id, ServerActionType::Reboot, $update->update_id,
            ),
            TelegramAction::ServerRevealPassword => $this->servers->revealPassword(
                $context, (int) $id, $update->update_id,
            ),
            TelegramAction::ServerDelete => $this->servers->confirmDelete($context, (int) $id),
            TelegramAction::ServerDeleteConfirm => $this->deleteStep($context, $update),
            TelegramAction::WalletPage => $this->wallet->show($context, $context->page()),
            TelegramAction::InvoicePage => $this->invoices->list($context, $context->page()),
            TelegramAction::InvoiceView => $this->invoices->view($context, (int) $id),
            default => $this->unknown($context->chatId, $normalized),
        };
    }

    /**
     * The one confirmation that destroys something.
     *
     * The server id is read from the delete intent this system wrote, never
     * from the button — so a confirmation from an old keyboard cannot be aimed
     * at a server the customer has selected since. A token that does not match
     * the live intent deletes nothing at all.
     */
    private function deleteStep(FlowContext $context, TelegramUpdate $update): void
    {
        $state = $this->flows->matching(
            $context->telegramUserId,
            FlowState::SERVER_DELETE,
            $context->flowToken(),
        );

        if ($state === null) {
            $this->telegram->sendMessage($context->chatId, ServerMessages::DELETE_EXPIRED, MainMenu::keyboard());

            return;
        }

        $this->servers->delete($context, $state, $update->update_id);
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
