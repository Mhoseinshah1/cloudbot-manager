<?php

declare(strict_types=1);

namespace App\Telegram\Flows;

use App\Audit\AuditEvent;
use App\Audit\AuditRecorder;
use App\Cloud\Enums\ProviderCapability;
use App\Enums\ServerActionType;
use App\Jobs\DeleteTelegramMessageJob;
use App\Models\Server;
use App\Models\ServerAction;
use App\Notifications\CustomerMessages;
use App\Servers\Exceptions\ServerActionNotAllowed;
use App\Servers\ServerAccess;
use App\Servers\ServerActionService;
use App\Telegram\Callbacks\CallbackGrammar;
use App\Telegram\TelegramApiClient;
use Illuminate\Contracts\Config\Repository as Config;

/**
 * Looking at a customer's servers, and operating them.
 *
 * Every lookup starts from the customer, so an id in a callback is a request to
 * look at something rather than a claim to own it. A stranger's server id finds
 * nothing, and the answer is the same one a nonexistent id gets — telling them
 * apart would make the bot a way of discovering which servers are real.
 *
 * The buttons offered come from asking the provider implementation what it can
 * do. A reboot button on a provider without reboot is a promise the system
 * cannot keep, and a hand-maintained list of who supports what drifts away from
 * the code the first time an adapter changes.
 *
 * Nothing here calls a provider. Every operation is written down as a
 * ServerAction and performed on the worker built for waiting; a customer's tap
 * must not sit inside somebody else's network timeout.
 *
 * Deleting takes two deliberate steps and a fresh intent token. A destructive
 * button that acts on the first press, from a keyboard that may be a week old,
 * is how somebody loses a server they did not mean to touch.
 */
final readonly class ServerManagementFlow
{
    private const PER_PAGE = 6;

    public function __construct(
        private TelegramApiClient $telegram,
        private ServerAccess $servers,
        private ServerActionService $actions,
        private FlowState $state,
        private AuditRecorder $audit,
        private Config $config,
    ) {}

    public function list(FlowContext $context, int $page = 1): void
    {
        $servers = $this->servers->paginate($context->customer, max(1, $page), self::PER_PAGE);

        if ($servers->total() === 0) {
            $this->telegram->sendMessage($context->chatId, ServerMessages::NONE, [
                'inline_keyboard' => [
                    [['text' => ServerMessages::BUY, 'callback_data' => 'menu:buy_server']],
                    [BuyMessages::mainMenuButton()],
                ],
            ]);

            return;
        }

        $lines = [ServerMessages::HEADING, ''];
        $buttons = [];

        foreach ($servers->items() as $server) {
            if (! $server instanceof Server) {
                continue;
            }

            $lines[] = ServerMessages::summary($server);
            $buttons[] = [[
                'text' => $server->name,
                'callback_data' => CallbackGrammar::serverView((int) $server->getKey()),
            ]];
        }

        if ($servers->lastPage() > 1) {
            $lines[] = '';
            $lines[] = 'صفحه '.$servers->currentPage().' از '.$servers->lastPage();
        }

        $navigation = [];

        if ($servers->currentPage() > 1) {
            $navigation[] = ['text' => BuyMessages::PREVIOUS, 'callback_data' => CallbackGrammar::serverPage($servers->currentPage() - 1)];
        }

        if ($servers->currentPage() < $servers->lastPage()) {
            $navigation[] = ['text' => BuyMessages::NEXT, 'callback_data' => CallbackGrammar::serverPage($servers->currentPage() + 1)];
        }

        if ($navigation !== []) {
            $buttons[] = $navigation;
        }

        $buttons[] = [BuyMessages::mainMenuButton()];

        $this->telegram->sendMessage($context->chatId, implode("\n", $lines), [
            'inline_keyboard' => $buttons,
        ]);
    }

    /**
     * One server, from what we already hold.
     *
     * No provider call. Opening a details page must not depend on a third
     * party's availability, and provider truth is synchronized by the inventory
     * sweep whose whole job that is.
     */
    public function view(FlowContext $context, int $serverId): void
    {
        $server = $this->servers->find($context->customer, $serverId);

        if (! $server instanceof Server) {
            $this->notFound($context);

            return;
        }

        $this->telegram->sendMessage($context->chatId, ServerMessages::details($server), [
            'inline_keyboard' => $this->keyboardFor($server),
        ]);
    }

    /**
     * Ask for something to be done, and say it has been asked for.
     *
     * The idempotency key includes the Telegram update, so a re-delivery of the
     * same tap resolves to the same action and one remote operation. A genuinely
     * new tap is a genuinely new request — which is right: a customer who
     * presses reboot twice, minutes apart, meant it twice.
     */
    public function requestAction(FlowContext $context, int $serverId, ServerActionType $action, int $updateId): void
    {
        try {
            $recorded = $this->actions->request(
                $context->customer,
                $serverId,
                $action,
                self::actionKey($updateId, $serverId, $action),
                ['telegram_update_id' => $updateId],
            );
        } catch (ServerActionNotAllowed $refused) {
            $this->refuse($context, $refused);

            return;
        }

        unset($recorded);

        $this->telegram->sendMessage($context->chatId, ServerMessages::requested($action), [
            'inline_keyboard' => [
                [['text' => ServerMessages::BACK, 'callback_data' => CallbackGrammar::serverView($serverId)]],
                [BuyMessages::mainMenuButton()],
            ],
        ]);
    }

    /**
     * The confirmation screen. Deletes nothing.
     *
     * A fresh intent token is written here and the confirm button carries only
     * that token — not the server id. So a confirmation from an old keyboard
     * cannot be aimed at a server the customer selected since: the id comes
     * from the state this step wrote, and the token is what proves the two
     * belong together.
     */
    public function confirmDelete(FlowContext $context, int $serverId): void
    {
        $server = $this->servers->find($context->customer, $serverId);

        if (! $server instanceof Server) {
            $this->notFound($context);

            return;
        }

        try {
            $this->servers->assertSupported($server, ServerActionType::Delete);
        } catch (ServerActionNotAllowed $refused) {
            $this->refuse($context, $refused);

            return;
        }

        $token = $this->state->begin($context->telegramUserId, FlowState::SERVER_DELETE, [
            'server_id' => (int) $server->getKey(),
        ]);

        $this->telegram->sendMessage($context->chatId, ServerMessages::deleteWarning($server), [
            'inline_keyboard' => [
                [['text' => ServerMessages::DELETE_CONFIRM, 'callback_data' => CallbackGrammar::serverDeleteConfirm($token)]],
                [['text' => ServerMessages::KEEP, 'callback_data' => CallbackGrammar::serverView((int) $server->getKey())]],
            ],
        ]);
    }

    /**
     * The confirmation itself.
     *
     * @param  array<string, scalar|null>  $state  Already matched against the token.
     */
    public function delete(FlowContext $context, array $state, int $updateId): void
    {
        $serverId = FlowState::intOf($state, 'server_id');

        if ($serverId === null) {
            $this->telegram->sendMessage($context->chatId, ServerMessages::DELETE_EXPIRED, [
                'inline_keyboard' => [[BuyMessages::mainMenuButton()]],
            ]);

            return;
        }

        try {
            $this->actions->request(
                $context->customer,
                $serverId,
                ServerActionType::Delete,
                // Keyed on the confirmed intent, not on the tap. Two deliveries
                // of one confirmation are one deletion; a customer who returns
                // and confirms again has made a new decision and would need a
                // new intent, which the state no longer holds.
                self::deleteKey((string) FlowState::stringOf($state, 'flow_ref')),
                ['telegram_update_id' => $updateId],
            );
        } catch (ServerActionNotAllowed $refused) {
            $this->refuse($context, $refused);

            return;
        }

        // Spent. A second confirmation from the same keyboard finds no live
        // intent and does nothing.
        $this->state->forget($context->telegramUserId);

        $this->telegram->sendMessage($context->chatId, ServerMessages::DELETE_REQUESTED, [
            'inline_keyboard' => [[BuyMessages::mainMenuButton()]],
        ]);
    }

    /**
     * Show the root password, once, in a message of its own.
     *
     * Read straight from the encrypted column at the moment of sending, and put
     * nowhere else: not in conversation state, not in callback data, not in the
     * action's metadata, not in the audit entry, not in a notification payload.
     * The one place it exists outside the database is this message, which is
     * why the message is scheduled for deletion and why it tells the customer
     * to change it.
     *
     * The audit records that a reveal happened, never what was revealed.
     */
    public function revealPassword(FlowContext $context, int $serverId, int $updateId): void
    {
        try {
            $action = $this->actions->request(
                $context->customer,
                $serverId,
                ServerActionType::RootPasswordReveal,
                self::actionKey($updateId, $serverId, ServerActionType::RootPasswordReveal),
                ['telegram_update_id' => $updateId],
            );
        } catch (ServerActionNotAllowed $refused) {
            $this->refuse($context, $refused);

            return;
        }

        if (! $action->isOpen()) {
            // Already delivered for this interaction. A re-delivered tap must
            // not send somebody's credential a second time.
            return;
        }

        $server = $this->servers->find($context->customer, $serverId);
        $password = $server?->root_password_encrypted;

        if (! $server instanceof Server || ! is_string($password) || $password === '') {
            $this->telegram->sendMessage($context->chatId, ServerMessages::NO_PASSWORD, [
                'inline_keyboard' => [[BuyMessages::mainMenuButton()]],
            ]);

            return;
        }

        $visible = $this->visibleSeconds();

        $sent = $this->telegram->sendMessage(
            $context->chatId,
            CustomerMessages::rootPassword($server->name, $password, $visible),
        );

        // Recorded after the send, so an audit entry never claims a reveal that
        // did not reach anybody. A crash between the two would repeat the
        // reveal on retry — unavoidable, since no database write commits
        // atomically with a Telegram send.
        $this->audit->record(
            AuditEvent::ServerPasswordRevealed,
            actor: $context->customer,
            subject: $server,
            metadata: [
                'server_id' => $server->getKey(),
                'user_id' => $context->customer->getKey(),
                'server_action_id' => $action->getKey(),
                // What was revealed is emphatically not recorded.
                'telegram_update_id' => $updateId,
            ],
        );

        $this->actions->settle($action, \App\Enums\ServerActionStatus::Succeeded);

        $messageId = $sent['message_id'] ?? null;

        if (is_int($messageId)) {
            // Best effort, and only ever that. The job carries a chat id and a
            // message id; it never learns the password.
            DeleteTelegramMessageJob::dispatch($context->chatId, $messageId)
                ->delay(now()->addSeconds($visible));
        }
    }

    /**
     * The buttons this server can actually offer.
     *
     * Derived from the provider implementation, so an operation the adapter
     * does not implement is never offered — and is refused again server-side if
     * somebody sends its callback anyway.
     *
     * @return list<list<array<string, string>>>
     */
    private function keyboardFor(Server $server): array
    {
        $id = (int) $server->getKey();
        $capabilities = $this->servers->capabilities($server);
        $live = $this->servers->isLive($server);

        $rows = [];

        if ($live && in_array(ProviderCapability::PowerControl, $capabilities, strict: true)) {
            $rows[] = [
                ['text' => ServerMessages::POWER_ON, 'callback_data' => CallbackGrammar::serverPowerOn($id)],
                ['text' => ServerMessages::POWER_OFF, 'callback_data' => CallbackGrammar::serverPowerOff($id)],
            ];
        }

        if ($live && in_array(ProviderCapability::Reboot, $capabilities, strict: true)) {
            $rows[] = [['text' => ServerMessages::REBOOT, 'callback_data' => CallbackGrammar::serverReboot($id)]];
        }

        if ($live && $server->root_password_encrypted !== null) {
            $rows[] = [['text' => ServerMessages::PASSWORD, 'callback_data' => CallbackGrammar::serverRevealPassword($id)]];
        }

        if ($live) {
            $rows[] = [['text' => ServerMessages::DELETE, 'callback_data' => CallbackGrammar::serverDelete($id)]];
        }

        $rows[] = [['text' => ServerMessages::BACK_TO_LIST, 'callback_data' => CallbackGrammar::serverPage(1)]];
        $rows[] = [BuyMessages::mainMenuButton()];

        return $rows;
    }

    /**
     * One tap, one operation.
     *
     * Built from the Telegram update id, which is unique per delivery, so a
     * re-delivery of the same tap resolves to the same action. The server and
     * the action are in the key too, because one update could in principle be
     * routed to only one of them but a collision would be silent.
     */
    public static function actionKey(int $updateId, int $serverId, ServerActionType $action): string
    {
        return "telegram:update:{$updateId}:server:{$serverId}:{$action->value}";
    }

    /** One confirmed delete intent, one deletion. */
    public static function deleteKey(string $intentToken): string
    {
        return 'telegram:delete_intent:'.$intentToken;
    }

    private function refuse(FlowContext $context, ServerActionNotAllowed $refused): void
    {
        $this->telegram->sendMessage($context->chatId, ServerMessages::refusal($refused), [
            'inline_keyboard' => [[BuyMessages::mainMenuButton()]],
        ]);
    }

    private function notFound(FlowContext $context): void
    {
        $this->telegram->sendMessage($context->chatId, ServerMessages::NOT_FOUND, [
            'inline_keyboard' => [[BuyMessages::mainMenuButton()]],
        ]);
    }

    private function visibleSeconds(): int
    {
        return max(5, (int) $this->config->get('cloudbot.server_credentials.reveal_visible_seconds', 60));
    }
}
