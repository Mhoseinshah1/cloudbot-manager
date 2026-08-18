<?php

namespace App\Services\Telegram\Flows;

use App\Contracts\Data\ProviderImageData;
use App\Models\ProviderImage;
use App\Models\ProviderLocation;
use App\Models\Server;
use App\Services\RenewalService;
use App\Services\ServerActionService;
use App\Services\Telegram\TelegramApiClient;
use App\Services\Telegram\TelegramMenuService;
use App\Services\Telegram\TelegramUserService;

class ServerActionsFlow
{
    public function __construct(
        private TelegramApiClient $api,
        private TelegramMenuService $menus,
        private TelegramUserService $users,
        private ServerActionService $actions,
        private RenewalService $renewal,
    ) {}

    public function handleCallback(int $chatId, int $messageId, int $telegramUserId, array $route): void
    {
        $action = $route[1] ?? '';

        match ($action) {
            'details' => $this->showDetails($chatId, $messageId, $telegramUserId, $route),
            'action' => $this->performAction($chatId, $telegramUserId, $route),
            'rebuild' => $this->handleRebuild($chatId, $messageId, $telegramUserId, $route),
            'renew' => $this->handleRenew($chatId, $messageId, $telegramUserId, $route),
            default => null,
        };
    }

    private function showDetails(int $chatId, int $messageId, int $telegramUserId, array $route): void
    {
        $serverId = (int) ($route[2] ?? 0);
        $server = $this->getOwnedServer($telegramUserId, $serverId);

        if ($server === null) {
            $this->api->sendMessage($chatId, 'سرور یافت نشد.');

            return;
        }

        $data = $this->formatServerDetails($server);
        $menu = $this->menus->serverDetails($data);

        $this->api->editMessageText($chatId, $messageId, $menu['text'], [
            'reply_markup' => ['inline_keyboard' => $menu['buttons']],
        ]);
    }

    private function performAction(int $chatId, int $telegramUserId, array $route): void
    {
        $actionType = $route[2] ?? '';
        $serverId = (int) ($route[3] ?? 0);

        $server = $this->getOwnedServer($telegramUserId, $serverId);
        if ($server === null) {
            $this->api->sendMessage($chatId, 'سرور یافت نشد.');

            return;
        }

        $user = $this->users->findByTelegramId($telegramUserId);
        if ($user === null) {
            return;
        }

        $validActions = ['power_on', 'power_off', 'reboot'];

        if (! in_array($actionType, $validActions, true)) {
            $this->api->sendMessage($chatId, 'عملیات نامعتبر.');

            return;
        }

        try {
            $result = $this->actions->perform($server, $actionType, $user);
            $label = match ($actionType) {
                'power_on' => 'روشن کردن',
                'power_off' => 'خاموش کردن',
                'reboot' => 'ریبوت',
            };
            $this->api->sendMessage($chatId, "✅ {$label} با موفقیت انجام شد.");
        } catch (\Throwable $e) {
            $this->api->sendMessage($chatId, "❌ خطا در {$actionType}: {$e->getMessage()}");
        }
    }

    private function handleRebuild(int $chatId, int $messageId, int $telegramUserId, array $route): void
    {
        $sub = $route[2] ?? '';
        $serverId = (int) ($route[3] ?? 0);

        if ($sub === '') {
            // Show rebuild confirmation
            $menu = $this->menus->rebuildConfirm($serverId);
            $this->api->editMessageText($chatId, $messageId, $menu['text'], [
                'reply_markup' => ['inline_keyboard' => $menu['buttons']],
            ]);

            return;
        }

        if ($sub === 'confirm') {
            // Show image selection
            $server = $this->getOwnedServer($telegramUserId, $serverId);
            if ($server === null) {
                $this->api->sendMessage($chatId, 'سرور یافت نشد.');

                return;
            }

            $images = ProviderImage::query()
                ->where('provider_id', $server->provider_id)
                ->where('enabled', true)
                ->whereNull('deprecated')
                ->get()
                ->map(fn ($img) => ['id' => $img->id, 'name' => $img->name])
                ->toArray();

            $menu = $this->menus->rebuildImageMenu($serverId, $images);
            $this->api->editMessageText($chatId, $messageId, $menu['text'], [
                'reply_markup' => ['inline_keyboard' => $menu['buttons']],
            ]);

            return;
        }

        if ($sub === 'pick') {
            $imageId = (int) ($route[4] ?? 0);
            $server = $this->getOwnedServer($telegramUserId, $serverId);
            $user = $this->users->findByTelegramId($telegramUserId);

            if ($server === null || $user === null) {
                $this->api->sendMessage($chatId, 'خطا.');

                return;
            }

            $image = ProviderImage::query()->where('id', $imageId)->where('enabled', true)->first();

            if ($image === null) {
                $this->api->sendMessage($chatId, 'سیستم عامل نامعتبر.');

                return;
            }

            try {
                $imageDto = ProviderImageData::fromArray([
                    ...$image->toArray(),
                    'id' => $image->provider_image_id,
                ]);

                $this->actions->perform($server, 'rebuild', $user, $imageDto);
                $this->api->sendMessage($chatId, "✅ نصب مجدد با موفقیت آغاز شد.\n\n⏳ لطفاً صبر کنید...");
            } catch (\Throwable $e) {
                $this->api->sendMessage($chatId, "❌ خطا: {$e->getMessage()}");
            }
        }
    }

    private function handleRenew(int $chatId, int $messageId, int $telegramUserId, array $route): void
    {
        $sub = $route[2] ?? '';
        $serverId = (int) ($route[3] ?? 0);

        if ($sub === '') {
            $server = $this->getOwnedServer($telegramUserId, $serverId);
            if ($server === null) {
                $this->api->sendMessage($chatId, 'سرور یافت نشد.');

                return;
            }

            // Calculate renewal price from product
            $price = $server->selling_price ?? 0;
            $newExpiry = $server->expires_at
                ? $server->expires_at->copy()->addMonth()->format('Y/m/d')
                : '-';

            $menu = $this->menus->renewMenu(
                $this->formatServerCompact($server),
                $price,
                $newExpiry,
            );

            $this->api->editMessageText($chatId, $messageId, $menu['text'], [
                'reply_markup' => ['inline_keyboard' => $menu['buttons']],
            ]);

            return;
        }

        if ($sub === 'pay') {
            $this->api->sendMessage($chatId, "💳 <b>پردازش تمدید...</b>\n\n⏳ لطفاً صبر کنید.");

            $server = $this->getOwnedServer($telegramUserId, $serverId);
            $user = $this->users->findByTelegramId($telegramUserId);

            if ($server === null || $user === null) {
                $this->api->sendMessage($chatId, '❌ خطا در تمدید سرویس.');

                return;
            }

            try {
                $result = $this->renewal->processRenewal($server, $user);
                $newExpiry = $result['new_expiry']?->format('Y/m/d') ?? '-';
                $this->api->sendMessage($chatId, "✅ تمدید با موفقیت انجام شد.\n\n📅 تاریخ جدید انقضا: {$newExpiry}");
            } catch (\Throwable $e) {
                $this->api->sendMessage($chatId, '❌ خطا در تمدید سرویس: '.$e->getMessage());
            }
        }
    }

    private function getOwnedServer(int $telegramUserId, int $serverId): ?Server
    {
        $user = $this->users->findByTelegramId($telegramUserId);

        if ($user === null) {
            return null;
        }

        return Server::query()
            ->where('id', $serverId)
            ->where('user_id', $user->id)
            ->with('location', 'product', 'provider')
            ->first();
    }

    public function formatServerCompact(Server $server): array
    {
        /** @var ProviderLocation|null $location */
        $location = $server->location;
        /** @var array<string, mixed> $plan */
        $plan = $server->plan_snapshot ?? [];

        return [
            'id' => $server->id,
            'status' => $server->status,
            'billing_mode' => $server->billing_mode,
            'location_name' => $location->name ?? '-',
            'country_code' => $location->country_code ?? '',
            'country_flag' => $this->countryFlag($location->country_code ?? ''),
            'vcpu' => $plan['vcpu'] ?? '-',
            'ram_gb' => isset($plan['ram_mb']) ? round($plan['ram_mb'] / 1024) : '-',
        ];
    }

    public function formatServerDetails(Server $server): array
    {
        $compact = $this->formatServerCompact($server);
        /** @var array<string, mixed> $plan */
        $plan = $server->plan_snapshot ?? [];
        /** @var array<string, mixed> $image */
        $image = $server->image_snapshot ?? [];

        $wallet = $server->user?->wallet;
        $balance = $wallet !== null ? $wallet->balance_toman : 0;
        $remainingHours = ($server->hourly_rate_toman ?? 0) > 0 ? intdiv($balance, $server->hourly_rate_toman) : 0;

        $periodStart = $server->billing_period_started_at?->format('Y/m/d H:i') ?? '-';
        $periodEnd = $server->billing_period_ends_at?->format('Y/m/d H:i') ?? '-';

        return array_merge($compact, [
            'ip_address' => $server->ip_address ?? '-',
            'os_name' => ($image['name'] ?? $image['os_distro'] ?? '').' '.($image['version'] ?? ''),
            'disk_gb' => $plan['disk_gb'] ?? '-',
            'expires_display' => $server->expires_at?->format('Y/m/d') ?? '-',
            'hourly_rate_display' => number_format($server->hourly_rate_toman ?? 0).' تومان',
            'wallet_display' => number_format($balance).' تومان',
            'billing_state' => $server->billing_state ?? 'active',
            'cap_display' => number_format($server->monthly_cap_toman ?? 0).' تومان',
            'period_charged_display' => number_format($server->current_period_charged ?? 0).' تومان',
            'period_display' => "{$periodStart} — {$periodEnd}",
        ]);
    }

    private function countryFlag(string $code): string
    {
        return match (strtoupper($code)) {
            'DE' => '🇩🇪', 'FI' => '🇫🇮', 'US' => '🇺🇸',
            'NL' => '🇳🇱', 'GB' => '🇬🇧', 'FR' => '🇫🇷',
            'SG' => '🇸🇬', 'IR' => '🇮🇷', default => '🌍',
        };
    }
}
