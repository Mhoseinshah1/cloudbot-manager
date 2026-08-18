<?php

namespace App\Services\Telegram;

use App\Models\Server;
use App\Services\Telegram\Flows\BuyServerFlow;
use App\Services\Telegram\Flows\ServerActionsFlow;
use App\Services\Telegram\Flows\WalletFlow;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Routes Telegram updates (messages + callbacks) to the appropriate handler.
 *
 * The router is the single entry point for all Telegram update processing.
 * It resolves the user, then dispatches to the correct flow handler based
 * on the update type and callback data prefix.
 */
class TelegramUpdateRouter
{
    public function __construct(
        private TelegramApiClient $api,
        private TelegramUserService $users,
        private TelegramStateService $state,
        private TelegramMenuService $menus,
        private BuyServerFlow $buyFlow,
        private WalletFlow $walletFlow,
        private ServerActionsFlow $serverActions,
    ) {}

    /**
     * Process a single Telegram update.
     *
     * @param  array<string, mixed>  $update
     */
    public function handle(array $update): void
    {
        if (isset($update['callback_query'])) {
            $this->handleCallback($update['callback_query']);
        } elseif (isset($update['message'])) {
            $this->handleMessage($update['message']);
        }
    }

    private function handleMessage(array $message): void
    {
        $chatId = $message['chat']['id'] ?? 0;
        $from = $message['from'] ?? [];

        if ($chatId === 0 || $from === []) {
            return;
        }

        $telegramUserId = (int) $from['id'];
        $user = $this->users->resolveOrCreate(
            $telegramUserId,
            $from['first_name'] ?? null,
            $from['last_name'] ?? null,
            $from['username'] ?? null,
            $chatId,
        );

        $text = $message['text'] ?? '';

        match (true) {
            $text === '/start' => $this->sendMainMenu($chatId),
            $text === '/help' => $this->sendHelp($chatId),
            str_starts_with($text, '/') => $this->api->sendMessage($chatId, "دستور نامعتبر است.\nاز منوی زیر استفاده کنید:"),
            default => $this->handleTextInput($chatId, $telegramUserId, $text),
        };
    }

    private function handleCallback(array $callbackQuery): void
    {
        $chatId = $callbackQuery['message']['chat']['id'] ?? 0;
        $messageId = $callbackQuery['message']['message_id'] ?? 0;
        $data = $callbackQuery['data'] ?? '';
        $queryId = $callbackQuery['id'] ?? '';
        $from = $callbackQuery['from'] ?? [];

        if ($chatId === 0 || $data === '') {
            $this->api->answerCallbackQuery($queryId);

            return;
        }

        $telegramUserId = (int) ($from['id'] ?? 0);
        $user = $this->users->resolveOrCreate(
            $telegramUserId,
            $from['first_name'] ?? null,
            $from['last_name'] ?? null,
            $from['username'] ?? null,
            $chatId,
        );

        // Route by callback_data prefix
        $route = explode(':', $data);
        $prefix = $route[0];

        try {
            match ($prefix) {
                'menu' => $this->handleMenuCallback($chatId, $messageId, $route),
                'buy' => $this->buyFlow->handleCallback($chatId, $messageId, $telegramUserId, $route),
                'wallet' => $this->walletFlow->handleCallback($chatId, $messageId, $telegramUserId, $route),
                'servers' => $this->handleServersList($chatId, $messageId, $telegramUserId, $route),
                'srv' => $this->serverActions->handleCallback($chatId, $messageId, $telegramUserId, $route),
                'profile' => $this->handleProfile($chatId, $user),
                'help' => $this->sendHelp($chatId),
                'invoices' => $this->handleInvoices($chatId, $telegramUserId, $route),
                default => null,
            };
        } catch (\Throwable $e) {
            Log::error('Telegram callback handler error', ['data' => $data, 'error' => $e->getMessage()]);
            $this->api->sendMessage($chatId, '❌ خطایی رخ داد. لطفاً دوباره تلاش کنید.');
        }

        $this->api->answerCallbackQuery($queryId);
    }

    private function handleMenuCallback(int $chatId, int $messageId, array $route): void
    {
        $action = $route[1] ?? '';

        if ($action === 'main') {
            $this->sendMainMenu($chatId, $messageId);
        }
    }

    private function handleServersList(int $chatId, int $messageId, int $telegramUserId, array $route): void
    {
        $page = (int) ($route[2] ?? 0);
        $user = $this->users->findByTelegramId($telegramUserId);

        if ($user === null) {
            return;
        }

        $perPage = config('telegram.servers_per_page', 5);
        $query = $user->servers()->with('location', 'provider')->orderByDesc('id');
        $total = $query->count();
        /** @var Collection<int, Server> $servers */
        $servers = $query->offset($page * $perPage)->limit($perPage)->get();

        $serverData = $servers->map(fn (Server $s) => $this->serverActions->formatServerCompact($s))->toArray();

        $menu = $this->menus->serverList($serverData, $page, $total);
        $this->api->editMessageText($chatId, $messageId, $menu['text'], [
            'reply_markup' => ['inline_keyboard' => $menu['buttons']],
        ]);
    }

    private function handleProfile(int $chatId, $user): void
    {
        $menu = $this->menus->profileMenu([
            'name' => $user->name,
            'username' => $user->telegramAccount->username ?? '-',
        ]);
        $this->api->sendMessage($chatId, $menu['text'], [
            'reply_markup' => ['inline_keyboard' => $menu['buttons']],
        ]);
    }

    private function handleInvoices(int $chatId, int $telegramUserId, array $route): void
    {
        $user = $this->users->findByTelegramId($telegramUserId);
        if ($user === null) {
            return;
        }

        $this->api->sendMessage($chatId, '🧾 <b>فاکتورها</b>\n\n(به‌زودی)');
    }

    private function sendMainMenu(int $chatId, ?int $messageId = null): void
    {
        $menu = $this->menus->mainMenu();

        if ($messageId !== null) {
            $this->api->editMessageText($chatId, $messageId, $menu['text'], [
                'reply_markup' => ['inline_keyboard' => $menu['buttons']],
            ]);
        } else {
            $this->api->sendMessage($chatId, $menu['text'], [
                'reply_markup' => ['inline_keyboard' => $menu['buttons']],
            ]);
        }
    }

    private function sendHelp(int $chatId): void
    {
        $menu = $this->menus->helpMenu();
        $this->api->sendMessage($chatId, $menu['text'], [
            'reply_markup' => ['inline_keyboard' => $menu['buttons']],
        ]);
    }

    private function handleTextInput(int $chatId, int $telegramUserId, string $text): void
    {
        $state = $this->state->get($telegramUserId);

        if ($state === null) {
            $this->api->sendMessage($chatId, 'لطفاً از منوی زیر استفاده کنید:');
            $this->sendMainMenu($chatId);

            return;
        }

        // Handle text input for pending top-up amount
        if (($state['flow'] ?? '') === 'wallet:topup:input') {
            $this->walletFlow->handleTopUpInput($chatId, $telegramUserId, $text);
        }
    }
}
