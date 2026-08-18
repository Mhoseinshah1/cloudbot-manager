<?php

namespace App\Services\Telegram;

use App\Events\LowBalanceWarningTriggered;
use App\Models\Server;
use App\Models\User;

/**
 * Handles billing Core notifications for Telegram delivery.
 *
 * Consumes Billing Core state/events; never duplicates threshold
 * calculations or billing logic.
 */
class TelegramNotificationService
{
    public function __construct(
        private TelegramApiClient $api,
        private TelegramUserService $users,
    ) {}

    /**
     * Called when a LowBalanceWarningTriggered event is dispatched.
     */
    public function handleLowBalanceWarning(LowBalanceWarningTriggered $event): void
    {
        $warning = $event->warning;
        $server = Server::query()->with('user')->find($warning->server_id);

        if ($server === null || $server->user === null) {
            return;
        }

        /** @var User|null $user */
        $user = $server->user;
        $chatId = $user !== null ? $this->users->getChatId($user) : null;
        if ($chatId === null) {
            return;
        }

        $balance = number_format($warning->balance_toman).' تومان';
        $hours = $warning->estimated_hours;
        $hoursText = $hours > 0 ? "{$hours} ساعت" : 'کمتر از ۱ ساعت';

        $text = "⚠️ <b>موجودی کیف پول رو به اتمام است</b>\n\n";
        $text .= "🖥 سرور: #{$server->id}\n";
        $text .= "💰 موجودی: {$balance}\n";
        $text .= "⏳ زمان تقریبی باقی‌مانده: {$hoursText}\n\n";
        $text .= 'لطفاً موجودی کیف پول خود را افزایش دهید.';

        $this->api->sendMessage($chatId, $text, [
            'reply_markup' => ['inline_keyboard' => [
                [['text' => '💰 افزایش موجودی', 'callback_data' => 'wallet:topup:start']],
            ]],
        ]);
    }

    /**
     * Notify user about billing state transitions.
     */
    public function notifyBillingStateChange(Server $server, string $oldState, string $newState): void
    {
        /** @var User|null $user */
        $user = $server->user;
        $chatId = $user !== null ? $this->users->getChatId($user) : null;
        if ($chatId === null) {
            return;
        }

        $text = match ($newState) {
            'low_balance' => "⚠️ <b>موجودی ناکافی</b>\n\nسرور #{$server->id} نیاز به شارژ کیف پول دارد.",
            'payment_due' => "⏳ <b>نیاز به پرداخت</b>\n\nسرور #{$server->id} وارد مرحله پرداخت شده است.",
            'grace' => $this->graceMessage($server),
            'lifecycle_action_pending' => "🔴 <b>اقدام خودکار</b>\n\nسرور #{$server} وارد مرحله اقدام خودکار شده است.",
            default => null,
        };

        if ($text !== null) {
            $this->api->sendMessage($chatId, $text, [
                'reply_markup' => ['inline_keyboard' => [
                    [['text' => '💰 افزایش موجودی', 'callback_data' => 'wallet:topup:start']],
                ]],
            ]);
        }
    }

    private function graceMessage(Server $server): string
    {
        $deadline = $server->grace_ends_at?->format('Y/m/d H:i') ?? '-';

        return "⏰ <b>مهلت پرداخت</b>\n\n".
            "سرور #{$server->id} وارد مهلت پرداخت شده است.\n".
            "📅 مهلت: {$deadline}\n\n".
            'لطفاً موجودی کیف پول خود را افزایش دهید تا سرویس قطع نشود.';
    }
}
