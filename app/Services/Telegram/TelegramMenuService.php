<?php

namespace App\Services\Telegram;

use App\Enums\BillingMode;
use App\Models\Product;

/**
 * Persian-first menu builder for the Telegram bot.
 *
 * All customer-facing text is in Persian (Farsi). The architecture
 * allows future localization by extracting strings to lang files.
 */
class TelegramMenuService
{
    // ── Main menu ──────────────────────────────────────────────────

    public function mainMenu(): array
    {
        return [
            'text' => "🖥 <b>منوی اصلی</b>\n\nلطفاً یکی از گزینه‌ها را انتخاب کنید:",
            'buttons' => [
                [['text' => '🖥 خرید سرور', 'callback_data' => 'buy:start']],
                [['text' => '📦 سرورهای من', 'callback_data' => 'servers:list:0']],
                [['text' => '💰 کیف پول', 'callback_data' => 'wallet:main']],
                [['text' => '🧾 فاکتورها', 'callback_data' => 'invoices:list']],
                [['text' => '👤 حساب کاربری', 'callback_data' => 'profile:main']],
                [['text' => 'ℹ️ راهنما', 'callback_data' => 'help:main']],
            ],
        ];
    }

    // ── Buy server flow ────────────────────────────────────────────

    public function billingTypeMenu(): array
    {
        $buttons = [];

        // Only show types with enabled active products
        $hasMonthly = Product::query()->where('billing_mode', BillingMode::Monthly->value)
            ->where('status', 'active')->where('enabled', true)->exists();
        $hasHourly = Product::query()->where('billing_mode', BillingMode::Hourly->value)
            ->where('status', 'active')->where('enabled', true)->exists();
        $hasCapped = Product::query()->where('billing_mode', BillingMode::HourlyCapped->value)
            ->where('status', 'active')->where('enabled', true)->exists();

        if ($hasMonthly) {
            $buttons[] = [['text' => '🗓 سرور ماهانه', 'callback_data' => 'buy:mode:monthly']];
        }
        if ($hasHourly) {
            $buttons[] = [['text' => '⏱ سرور ساعتی', 'callback_data' => 'buy:mode:hourly']];
        }
        if ($hasCapped) {
            $buttons[] = [['text' => '⏱ ساعتی با سقف ماهانه', 'callback_data' => 'buy:mode:hourly_capped']];
        }

        $buttons[] = [['text' => '⬅️ بازگشت', 'callback_data' => 'menu:main']];

        return [
            'text' => "🖥 <b>خرید سرور</b>\n\nنوع سرویس مورد نظر خود را انتخاب کنید:",
            'buttons' => $buttons,
        ];
    }

    public function locationMenu(string $billingMode, array $locations): array
    {
        $buttons = [];

        foreach ($locations as $loc) {
            $flag = $this->countryFlag($loc['country_code'] ?? '');
            $buttons[] = [['text' => "{$flag} {$loc['name']}", 'callback_data' => "buy:loc:{$billingMode}:{$loc['id']}"]];
        }

        $buttons[] = [['text' => '⬅️ بازگشت', 'callback_data' => 'buy:start']];

        return [
            'text' => "🌍 <b>انتخاب لوکیشن</b>\n\nلوکیشن سرور خود را انتخاب کنید:",
            'buttons' => $buttons,
        ];
    }

    public function planMenu(string $billingMode, int $locationId, array $plans): array
    {
        $buttons = [];

        foreach ($plans as $plan) {
            $label = "{$plan['vcpu']} CPU / {$plan['ram_mb_label']} RAM / {$plan['disk_gb']} GB";
            $buttons[] = [['text' => $label, 'callback_data' => "buy:plan:{$billingMode}:{$locationId}:{$plan['id']}"]];
        }

        $buttons[] = [['text' => '⬅️ بازگشت', 'callback_data' => "buy:loc:{$billingMode}"]];

        return [
            'text' => "⚙️ <b>انتخاب پلن</b>\n\nپلن مورد نظر خود را انتخاب کنید:",
            'buttons' => $buttons,
        ];
    }

    public function imageMenu(string $billingMode, int $locationId, int $planId, array $images): array
    {
        $buttons = [];

        foreach ($images as $img) {
            $buttons[] = [['text' => $img['name'], 'callback_data' => "buy:img:{$billingMode}:{$locationId}:{$planId}:{$img['id']}"]];
        }

        $buttons[] = [['text' => '⬅️ بازگشت', 'callback_data' => "buy:plan:{$billingMode}:{$locationId}"]];

        return [
            'text' => "🐧 <b>انتخاب سیستم عامل</b>\n\nسیستم عامل مورد نظر را انتخاب کنید:",
            'buttons' => $buttons,
        ];
    }

    public function confirmPurchase(array $details): string
    {
        $text = "🖥 <b>مشخصات سرور</b>\n\n";
        $text .= "🌍 لوکیشن: {$details['location_name']}\n";
        $text .= "⚙️ {$details['vcpu']} vCPU\n";
        $text .= "💾 {$details['ram_mb_label']} RAM\n";
        $text .= "💽 {$details['disk_gb']} GB SSD\n";
        $text .= "🐧 {$details['image_name']}\n\n";

        $modeLabel = match ($details['billing_mode']) {
            'monthly' => '🗓 ماهانه',
            'hourly' => '⏱ ساعتی',
            'hourly_capped' => '⏱ ساعتی با سقف ماهانه',
            default => $details['billing_mode'],
        };

        $text .= "🗓 نوع سرویس: {$modeLabel}\n\n";
        $text .= "💰 مبلغ: <b>{$details['price_display']}</b>\n";

        if ($details['billing_mode'] === 'hourly' || $details['billing_mode'] === 'hourly_capped') {
            $text .= "\n💰 موجودی کیف پول: {$details['wallet_display']}\n";

            if ($details['billing_mode'] === 'hourly_capped') {
                $text .= "🛑 سقف دوره: {$details['cap_display']}\n";
                $text .= "\n<i>هزینه به‌صورت ساعتی محاسبه می‌شود و در هر دوره صورتحساب از سقف تعیین‌شده بیشتر نمی‌شود.</i>\n";
            }
        }

        return $text;
    }

    public function confirmButtons(string $billingMode, int $locationId, int $planId, int $imageId): array
    {
        $payload = "{$billingMode}:{$locationId}:{$planId}:{$imageId}";

        $buttons = [
            [['text' => '✅ تأیید و پرداخت', 'callback_data' => "buy:confirm:{$payload}"]],
            [['text' => '⬅️ بازگشت', 'callback_data' => "buy:img:{$billingMode}:{$locationId}:{$planId}"]],
            [['text' => '❌ لغو', 'callback_data' => 'menu:main']],
        ];

        return $buttons;
    }

    // ── Wallet ─────────────────────────────────────────────────────

    public function walletMenu(int $balanceToman): array
    {
        $balanceDisplay = number_format($balanceToman).' تومان';

        return [
            'text' => "💰 <b>کیف پول</b>\n\nموجودی فعلی: <b>{$balanceDisplay}</b>",
            'buttons' => [
                [['text' => '➕ افزایش موجودی', 'callback_data' => 'wallet:topup:start']],
                [['text' => '📜 تراکنش‌ها', 'callback_data' => 'wallet:transactions:0']],
                [['text' => '⬅️ بازگشت', 'callback_data' => 'menu:main']],
            ],
        ];
    }

    public function topUpMenu(): array
    {
        return [
            'text' => "➕ <b>افزایش موجودی</b>\n\nمبلغ مورد نظر را به تومان وارد کنید:",
            'buttons' => [
                [['text' => '50,000 تومان', 'callback_data' => 'wallet:topup:amount:50000']],
                [['text' => '100,000 تومان', 'callback_data' => 'wallet:topup:amount:100000']],
                [['text' => '250,000 تومان', 'callback_data' => 'wallet:topup:amount:250000']],
                [['text' => '500,000 تومان', 'callback_data' => 'wallet:topup:amount:500000']],
                [['text' => '⬅️ بازگشت', 'callback_data' => 'wallet:main']],
            ],
        ];
    }

    // ── Server list & details ──────────────────────────────────────

    public function serverList(array $servers, int $page, int $total): array
    {
        if ($servers === []) {
            return [
                'text' => "📦 <b>سرورهای من</b>\n\nشما هنوز سروری ندارید.",
                'buttons' => [
                    [['text' => '🖥 خرید سرور', 'callback_data' => 'buy:start']],
                    [['text' => '⬅️ بازگشت', 'callback_data' => 'menu:main']],
                ],
            ];
        }

        $text = "📦 <b>سرورهای من</b>\n\n";

        foreach ($servers as $server) {
            $icon = match ($server['status']) {
                'running' => '🟢',
                'off' => '🔴',
                'provisioning' => '🟡',
                default => '⚪',
            };

            $modeLabel = match ($server['billing_mode']) {
                'monthly' => '🗓 ماهانه',
                'hourly' => '⏱ ساعتی',
                'hourly_capped' => '⏱ سقف‌دار',
                default => '',
            };

            $text .= "{$icon} #{$server['id']}\n";
            $text .= "{$server['country_flag']} {$server['location_name']}\n";
            $text .= "{$server['vcpu']} CPU / {$server['ram_gb']} GB\n";
            $text .= "{$modeLabel}\n\n";
        }

        $buttons = [];

        foreach ($servers as $server) {
            $buttons[] = [['text' => "📋 سرور #{$server['id']}", 'callback_data' => "srv:details:{$server['id']}"]];
        }

        // Pagination
        $nav = [];
        if ($page > 0) {
            $nav[] = ['text' => '⬅️', 'callback_data' => 'servers:list:'.($page - 1)];
        }
        if (($page + 1) * config('telegram.servers_per_page', 5) < $total) {
            $nav[] = ['text' => '➡️', 'callback_data' => 'servers:list:'.($page + 1)];
        }
        if ($nav !== []) {
            $buttons[] = $nav;
        }

        $buttons[] = [['text' => '⬅️ بازگشت', 'callback_data' => 'menu:main']];

        return ['text' => $text, 'buttons' => $buttons];
    }

    public function serverDetails(array $server): array
    {
        $modeLabel = match ($server['billing_mode']) {
            'monthly' => '🗓 ماهانه',
            'hourly' => '⏱ ساعتی',
            'hourly_capped' => '⏱ ساعتی با سقف ماهانه',
            default => $server['billing_mode'],
        };

        $statusLabel = match ($server['status']) {
            'running' => '🟢 فعال',
            'off' => '🔴 خاموش',
            'provisioning' => '🟡 در حال ساخت',
            'suspended' => '⛔ تعلیق',
            default => "⚪ {$server['status']}",
        };

        $text = "🖥 <b>سرور #{$server['id']}</b>\n\n";
        $text .= "📊 وضعیت: {$statusLabel}\n";
        $text .= "🌐 IP: {$server['ip_address']}\n";
        $text .= "🌍 {$server['country_flag']} {$server['location_name']}\n";
        $text .= "⚙️ {$server['vcpu']} CPU / {$server['ram_gb']} GB RAM / {$server['disk_gb']} GB\n";
        $text .= "🐧 {$server['os_name']}\n";
        $text .= "🗓 نوع سرویس: {$modeLabel}\n";

        if ($server['billing_mode'] === 'monthly') {
            $text .= "📅 انقضا: {$server['expires_display']}\n";
        }

        if ($server['billing_mode'] === 'hourly' || $server['billing_mode'] === 'hourly_capped') {
            $text .= "💰 نرخ: {$server['hourly_rate_display']}/ساعت\n";
            $text .= "💰 موجودی: {$server['wallet_display']}\n";

            $stateLabel = match ($server['billing_state']) {
                'active' => '✅ فعال',
                'low_balance' => '⚠️ موجودی کم',
                'payment_due' => '⏳ نیاز به پرداخت',
                'grace' => '⏰ مهلت پرداخت',
                default => $server['billing_state'],
            };
            $text .= "📊 وضعیت صورتحساب: {$stateLabel}\n";
        }

        if ($server['billing_mode'] === 'hourly_capped') {
            $text .= "🛑 سقف دوره: {$server['cap_display']}\n";
            $text .= "📊 مصرف دوره: {$server['period_charged_display']}\n";
            $text .= "📅 دوره: {$server['period_display']}\n";
        }

        $buttons = [
            [
                ['text' => '▶️ روشن کردن', 'callback_data' => "srv:action:power_on:{$server['id']}"],
                ['text' => '⏹ خاموش کردن', 'callback_data' => "srv:action:power_off:{$server['id']}"],
            ],
            [
                ['text' => '🔄 ریبوت', 'callback_data' => "srv:action:reboot:{$server['id']}"],
                ['text' => '♻️ نصب مجدد', 'callback_data' => "srv:rebuild:{$server['id']}"],
            ],
        ];

        if ($server['billing_mode'] === 'monthly') {
            $buttons[] = [['text' => '💳 تمدید سرویس', 'callback_data' => "srv:renew:{$server['id']}"]];
        } else {
            $buttons[] = [['text' => '💰 افزایش موجودی', 'callback_data' => 'wallet:topup:start']];
        }

        $buttons[] = [['text' => '⬅️ بازگشت', 'callback_data' => 'servers:list:0']];

        return ['text' => $text, 'buttons' => $buttons];
    }

    // ── Rebuild ────────────────────────────────────────────────────

    public function rebuildConfirm(int $serverId): array
    {
        return [
            'text' => "⚠️ <b>警告</b>\n\nبا نصب مجدد، تمام اطلاعات سرور حذف خواهد شد.\n\nآیا مطمئن هستید؟",
            'buttons' => [
                [['text' => '✅ تأیید نصب مجدد', 'callback_data' => "srv:rebuild:confirm:{$serverId}"]],
                [['text' => '❌ لغو', 'callback_data' => "srv:details:{$serverId}"]],
            ],
        ];
    }

    public function rebuildImageMenu(int $serverId, array $images): array
    {
        $buttons = [];

        foreach ($images as $img) {
            $buttons[] = [['text' => $img['name'], 'callback_data' => "srv:rebuild:pick:{$serverId}:{$img['id']}"]];
        }

        $buttons[] = [['text' => '❌ لغو', 'callback_data' => "srv:details:{$serverId}"]];

        return [
            'text' => '♻️ <b>انتخاب سیستم عامل جدید</b>',
            'buttons' => $buttons,
        ];
    }

    // ── Renewal ────────────────────────────────────────────────────

    public function renewMenu(array $server, int $renewalPrice, string $newExpiry): array
    {
        $priceDisplay = number_format($renewalPrice).' تومان';

        return [
            'text' => "💳 <b>تمدید سرویس</b>\n\n".
                "🖥 سرور: #{$server['id']}\n".
                "📅 انقضای فعلی: {$server['expires_display']}\n".
                "📅 تمدید تا: {$newExpiry}\n\n".
                "💰 مبلغ تمدید: <b>{$priceDisplay}</b>",
            'buttons' => [
                [['text' => '✅ پرداخت و تمدید', 'callback_data' => "srv:renew:pay:{$server['id']}"]],
                [['text' => '❌ لغو', 'callback_data' => "srv:details:{$server['id']}"]],
            ],
        ];
    }

    // ── Profile & Help ─────────────────────────────────────────────

    public function profileMenu(array $user): array
    {
        $text = "👤 <b>حساب کاربری</b>\n\n";
        $text .= "نام: {$user['name']}\n";
        $text .= "نام کاربری تلگرام: @{$user['username']}\n";

        return [
            'text' => $text,
            'buttons' => [
                [['text' => '⬅️ بازگشت', 'callback_data' => 'menu:main']],
            ],
        ];
    }

    public function helpMenu(): array
    {
        return [
            'text' => "ℹ️ <b>راهنما</b>\n\n".
                "این ربات برای خرید و مدیریت سرورهای VPS استفاده می‌شود.\n\n".
                "🖥 <b>خرید سرور</b>: انتخاب نوع سرویس، لوکیشن، پلن و سیستم عامل\n".
                "📦 <b>سرورهای من</b>: مشاهده و مدیریت سرورهای فعال\n".
                "💰 <b>کیف پول</b>: مشاهده موجودی و افزایش موجودی\n\n".
                '💡 برای پشتیبانی با ما تماس بگیرید.',
            'buttons' => [
                [['text' => '⬅️ بازگشت', 'callback_data' => 'menu:main']],
            ],
        ];
    }

    // ── Helpers ────────────────────────────────────────────────────

    private function countryFlag(string $countryCode): string
    {
        $flags = [
            'DE' => '🇩🇪', 'FI' => '🇫🇮', 'US' => '🇺🇸', 'NL' => '🇳🇱',
            'GB' => '🇬🇧', 'FR' => '🇫🇷', 'SG' => '🇸🇬', 'IR' => '🇮🇷',
        ];

        return $flags[strtoupper($countryCode)] ?? '🌍';
    }
}
