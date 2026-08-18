<?php

namespace App\Services\Telegram\Flows;

use App\Models\Payment;
use App\Models\Wallet;
use App\Services\OrderService;
use App\Services\PaymentService;
use App\Services\Telegram\TelegramApiClient;
use App\Services\Telegram\TelegramMenuService;
use App\Services\Telegram\TelegramStateService;
use App\Services\Telegram\TelegramUserService;
use Illuminate\Support\Facades\Cache;

class WalletFlow
{
    public function __construct(
        private TelegramApiClient $api,
        private TelegramStateService $state,
        private TelegramMenuService $menus,
        private TelegramUserService $users,
        private OrderService $orders,
        private PaymentService $payments,
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
            if (! $this->validAmount($amount)) {
                $this->sendAmountValidationError($chatId);

                return;
            }

            $this->processTopUp($chatId, $telegramUserId, $amount);
        }
    }

    public function handleTopUpInput(int $chatId, int $telegramUserId, string $text): void
    {
        $normalized = str_replace([',', ' '], '', $text);

        if ($normalized === '' || ! ctype_digit($normalized)) {
            $this->api->sendMessage($chatId, 'لطفاً یک عدد صحیح وارد کنید:');

            return;
        }

        $amount = (int) $normalized;

        if (! $this->validAmount($amount)) {
            $this->sendAmountValidationError($chatId);

            return;
        }

        $this->processTopUp($chatId, $telegramUserId, $amount);
    }

    /**
     * Wallet top-ups always use the financial domain flow. In development,
     * TELEGRAM_ALLOW_FREE_TOPUP may auto-confirm the ManualGateway payment;
     * with the default false value, the payment remains pending and the
     * wallet is not credited until a real/manual payment confirmation occurs.
     */
    private function processTopUp(int $chatId, int $telegramUserId, int $amount): void
    {
        $user = $this->users->findByTelegramId($telegramUserId);
        if ($user === null) {
            return;
        }

        $idempotencyKey = "wallet-topup:{$telegramUserId}:{$amount}";

        if (! Cache::add($idempotencyKey, true, 600)) {
            $this->api->sendMessage($chatId, '⏳ درخواست افزایش موجودی شما در حال پردازش است.');

            return;
        }

        try {
            $gateway = (string) config('telegram.topup_gateway', 'manual');
            $order = $this->orders->createTopUpOrder($user, $amount);
            $invoice = $this->orders->createInvoice($order, $gateway);
            $payment = $this->payments->start($invoice, $gateway);

            if ((bool) config('telegram.allow_free_topup', false)) {
                $payment = $this->payments->confirm($payment, ['approved' => true], $user);
            }

            if ($payment->status === Payment::STATUS_PAID) {
                $wallet = $user->fresh()->wallet;
                $balance = $wallet !== null ? $wallet->balance_toman : 0;
                $display = number_format($balance).' تومان';

                $this->api->sendMessage($chatId, "✅ افزایش موجودی با موفقیت انجام شد.\n\n💰 موجودی فعلی: <b>{$display}</b>");
            } else {
                $this->api->sendMessage(
                    $chatId,
                    "💳 درخواست افزایش موجودی ثبت شد.\n\nمبلغ: <b>".number_format($amount)." تومان</b>\nپس از تأیید پرداخت، کیف پول شما خودکار شارژ می‌شود."
                );
            }

            $this->state->clear($telegramUserId);
        } catch (\Throwable $e) {
            Cache::forget($idempotencyKey);
            throw $e;
        }
    }

    private function validAmount(int $amount): bool
    {
        $min = max(1, (int) config('telegram.topup_min_toman', 10000));
        $max = max($min, (int) config('telegram.topup_max_toman', 50000000));

        return $amount >= $min && $amount <= $max;
    }

    private function sendAmountValidationError(int $chatId): void
    {
        $min = number_format((int) config('telegram.topup_min_toman', 10000));
        $max = number_format((int) config('telegram.topup_max_toman', 50000000));

        $this->api->sendMessage($chatId, "مبلغ باید بین {$min} تا {$max} تومان باشد.");
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
