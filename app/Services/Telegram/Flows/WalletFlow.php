<?php

namespace App\Services\Telegram\Flows;

use App\Models\Wallet;
use App\Services\Telegram\TelegramApiClient;
use App\Services\Telegram\TelegramMenuService;
use App\Services\Telegram\TelegramStateService;
use App\Services\Telegram\TelegramUserService;
use App\Services\WalletService;

class WalletFlow
{
    public function __construct(
        private TelegramApiClient $api,
        private TelegramStateService $state,
        private TelegramMenuService $menus,
        private TelegramUserService $users,
        private WalletService $wallets,
    ) {}

    public function handleCallback(int $chatId, int $messageId, int $telegramUserId, array $route): void
    {
        $action = $route[1] ?? '';

        match ($action) {
            'main' => $this->showBalance($chatId, $messageId, $telegramUserId),
            'topup' => $this->handleTopUp($chatId, $messageId, $telegramUserId, $route),
            'transactions' => $this->showTransactions($chatId, $messageId, $telegramUserId, $route),
            default => null,
        };
    }

    private function showBalance(int $chatId, int $messageId, int $telegramUserId): void
    {
        $user = $this->users->findByTelegramId($telegramUserId);
        if ($user === null) {
            return;
        }

        $wallet = $user->wallet;
        /** @var Wallet|null $wallet */
        $balance = $wallet !== null ? $wallet->balance_toman : 0;
        $menu = $this->menus->walletMenu($balance);
        $this->api->editMessageText($chatId, $messageId, $menu['text'], [
            'reply_markup' => ['inline_keyboard' => $menu['buttons']],
        ]);
    }

    private function handleTopUp(int $chatId, int $messageId, int $telegramUserId, array $route): void
    {
        $sub = $route[2] ?? '';

        if ($sub === 'start') {
            $menu = $this->menus->topUpMenu();
            $this->api->editMessageText($chatId, $messageId, $menu['text'], [
                'reply_markup' => ['inline_keyboard' => $menu['buttons']],
            ]);

            return;
        }

        if ($sub === 'amount') {
            $amount = (int) ($route[3] ?? 0);
            if ($amount <= 0) {
                $this->api->sendMessage($chatId, 'مبلغ نامعتبر است.');

                return;
            }
            $this->processTopUp($chatId, $telegramUserId, $amount);
        }
    }

    public function handleTopUpInput(int $chatId, int $telegramUserId, string $text): void
    {
        $amount = (int) str_replace([',', ' '], '', $text);

        if ($amount <= 0) {
            $this->api->sendMessage($chatId, 'لطفاً یک عدد صحیح وارد کنید:');

            return;
        }

        $this->processTopUp($chatId, $telegramUserId, $amount);
    }

    /**
     * Credits the wallet directly for dev/test mode.
     *
     * Production would route through Order → Invoice → Payment flow with
     * a real payment gateway. For development, the ManualGateway path
     * credits the wallet immediately.
     */
    private function processTopUp(int $chatId, int $telegramUserId, int $amount): void
    {
        $user = $this->users->findByTelegramId($telegramUserId);
        if ($user === null) {
            return;
        }

        $this->wallets->credit($user, $amount, 'Telegram wallet top-up');

        $wallet = $user->fresh()->wallet;
        $balance = $wallet !== null ? $wallet->balance_toman : 0;
        $display = number_format($balance).' تومان';

        $this->api->sendMessage($chatId, "✅ افزایش موجودی با موفقیت انجام شد.\n\n💰 موجودی فعلی: <b>{$display}</b>");
        $this->state->clear($telegramUserId);
    }

    private function showTransactions(int $chatId, int $messageId, int $telegramUserId, array $route): void
    {
        $user = $this->users->findByTelegramId($telegramUserId);
        if ($user === null) {
            return;
        }

        $wallet = $user->wallet;
        /** @var Wallet|null $wallet */
        if ($wallet === null) {
            $this->api->sendMessage($chatId, 'کیف پولی وجود ندارد.');

            return;
        }

        $transactions = $wallet->transactions()
            ->orderByDesc('created_at')
            ->limit(20)
            ->get();

        if ($transactions->isEmpty()) {
            $this->api->sendMessage($chatId, '📜 تراکنشی وجود ندارد.');

            return;
        }

        $text = "📜 <b>تراکنش‌ها</b>\n\n";

        foreach ($transactions as $tx) {
            $icon = $tx->type === 'credit' ? '➕' : '➖';
            $amount = number_format($tx->amount_toman).' تومان';
            $date = $tx->created_at->format('Y/m/d H:i');
            $text .= "{$icon} {$amount} — {$tx->description}\n";
            $text .= "   📅 {$date}\n\n";
        }

        $this->api->editMessageText($chatId, $messageId, $text, [
            'reply_markup' => ['inline_keyboard' => [
                [['text' => '⬅️ بازگشت', 'callback_data' => 'wallet:main']],
            ]],
        ]);
    }
}
