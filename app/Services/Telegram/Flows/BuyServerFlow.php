<?php

namespace App\Services\Telegram\Flows;

use App\Enums\BillingMode;
use App\Models\Product;
use App\Models\ProviderImage;
use App\Models\ProviderLocation;
use App\Models\ProviderPlan;
use App\Services\HourlyBillingService;
use App\Services\OrderService;
use App\Services\PaymentService;
use App\Services\PricingService;
use App\Services\Telegram\TelegramApiClient;
use App\Services\Telegram\TelegramMenuService;
use App\Services\Telegram\TelegramStateService;
use App\Services\Telegram\TelegramUserService;
use Illuminate\Support\Facades\Cache;

/**
 * Handles the complete buy-server flow:
 *
 * billing mode → location → plan → image → confirm → payment → provision
 *
 * All financial operations go through existing domain services.
 * Telegram never creates financial records directly.
 */
class BuyServerFlow
{
    public function __construct(
        private TelegramApiClient $api,
        private TelegramStateService $state,
        private TelegramMenuService $menus,
        private TelegramUserService $users,
        private OrderService $orders,
        private PaymentService $payments,

        private HourlyBillingService $billing,
    ) {}

    public function handleCallback(int $chatId, int $messageId, int $telegramUserId, array $route): void
    {
        $action = $route[1] ?? '';

        match ($action) {
            'start' => $this->showBillingType($chatId, $messageId),
            'mode' => $this->selectBillingMode($chatId, $messageId, $telegramUserId, $route),
            'loc' => $this->selectLocation($chatId, $messageId, $telegramUserId, $route),
            'plan' => $this->selectPlan($chatId, $messageId, $telegramUserId, $route),
            'img' => $this->selectImage($chatId, $messageId, $telegramUserId, $route),
            'confirm' => $this->confirmAndPay($chatId, $messageId, $telegramUserId, $route),
            default => null,
        };
    }

    private function showBillingType(int $chatId, int $messageId): void
    {
        $menu = $this->menus->billingTypeMenu();
        $this->api->editMessageText($chatId, $messageId, $menu['text'], [
            'reply_markup' => ['inline_keyboard' => $menu['buttons']],
        ]);
    }

    private function selectBillingMode(int $chatId, int $messageId, int $telegramUserId, array $route): void
    {
        $mode = $route[2] ?? '';

        if (! in_array($mode, ['monthly', 'hourly', 'hourly_capped'], true)) {
            $this->api->sendMessage($chatId, 'نوع سرویس نامعتبر است.');

            return;
        }

        $this->state->set($telegramUserId, [
            'flow' => 'buy',
            'billing_mode' => $mode,
        ]);

        // Get enabled locations that have products for this billing mode
        $locationIds = Product::query()
            ->where('billing_mode', $mode)
            ->where('status', 'active')
            ->where('enabled', true)
            ->pluck('provider_plan_id');

        $providerPlanIds = ProviderPlan::query()
            ->whereIn('id', $locationIds)
            ->where('enabled', true)
            ->pluck('provider_id');

        $locations = ProviderLocation::query()
            ->whereIn('provider_id', $providerPlanIds)
            ->where('enabled', true)
            ->get()
            ->map(fn ($l) => ['id' => $l->id, 'name' => $l->name, 'country_code' => $l->country_code])
            ->toArray();

        if ($locations === []) {
            $this->api->sendMessage($chatId, '⚠️ در حال حاضر لوکیشنی برای این نوع سرویس موجود نیست.');

            return;
        }

        $menu = $this->menus->locationMenu($mode, $locations);
        $this->api->editMessageText($chatId, $messageId, $menu['text'], [
            'reply_markup' => ['inline_keyboard' => $menu['buttons']],
        ]);
    }

    private function selectLocation(int $chatId, int $messageId, int $telegramUserId, array $route): void
    {
        $mode = $route[2] ?? '';
        $locationId = (int) ($route[3] ?? 0);

        $location = ProviderLocation::query()
            ->where('id', $locationId)
            ->where('enabled', true)
            ->first();

        if ($location === null) {
            $this->api->sendMessage($chatId, 'لوکیشن نامعتبر یا غیرفعال است.');

            return;
        }

        $this->state->update($telegramUserId, ['location_id' => $locationId]);

        // Get plans that have products for this mode at this location
        $products = Product::query()
            ->where('billing_mode', $mode)
            ->where('status', 'active')
            ->where('enabled', true)
            ->get();

        $planIds = $products->pluck('provider_plan_id')->unique()->values();
        $planMap = ProviderPlan::query()
            ->whereIn('id', $planIds)
            ->where('enabled', true)
            ->get()
            ->keyBy('id');

        $plans = $products
            ->map(fn ($p) => $planMap->get($p->provider_plan_id))
            ->filter()
            ->unique('id')
            ->values()
            ->map(fn ($plan) => [
                'id' => $plan->id,
                'vcpu' => $plan->vcpu,
                'ram_mb' => $plan->ram_mb,
                'ram_mb_label' => $plan->ram_mb >= 1024 ? round($plan->ram_mb / 1024).' GB' : $plan->ram_mb.' MB',
                'disk_gb' => $plan->disk_gb,
            ])
            ->toArray();

        if ($plans === []) {
            $this->api->sendMessage($chatId, '⚠️ پلنی برای این لوکیشن موجود نیست.');

            return;
        }

        $menu = $this->menus->planMenu($mode, $locationId, $plans);
        $this->api->editMessageText($chatId, $messageId, $menu['text'], [
            'reply_markup' => ['inline_keyboard' => $menu['buttons']],
        ]);
    }

    private function selectPlan(int $chatId, int $messageId, int $telegramUserId, array $route): void
    {
        $mode = $route[2] ?? '';
        $locationId = (int) ($route[3] ?? 0);
        $planId = (int) ($route[4] ?? 0);

        $plan = ProviderPlan::query()
            ->where('id', $planId)
            ->where('enabled', true)
            ->first();

        if ($plan === null) {
            $this->api->sendMessage($chatId, 'پلن نامعتبر یا غیرفعال است.');

            return;
        }

        $this->state->update($telegramUserId, ['plan_id' => $planId]);

        // Get compatible images for this provider
        $images = ProviderImage::query()
            ->where('provider_id', $plan->provider_id)
            ->where('enabled', true)
            ->whereNull('deprecated')
            ->get()
            ->map(fn ($img) => ['id' => $img->id, 'name' => $img->name])
            ->toArray();

        if ($images === []) {
            $this->api->sendMessage($chatId, '⚠️ سیستم عاملی برای این پلن موجود نیست.');

            return;
        }

        $menu = $this->menus->imageMenu($mode, $locationId, $planId, $images);
        $this->api->editMessageText($chatId, $messageId, $menu['text'], [
            'reply_markup' => ['inline_keyboard' => $menu['buttons']],
        ]);
    }

    private function selectImage(int $chatId, int $messageId, int $telegramUserId, array $route): void
    {
        $mode = $route[2] ?? '';
        $locationId = (int) ($route[3] ?? 0);
        $planId = (int) ($route[4] ?? 0);
        $imageId = (int) ($route[5] ?? 0);

        $image = ProviderImage::query()
            ->where('id', $imageId)
            ->where('enabled', true)
            ->whereNull('deprecated')
            ->first();

        if ($image === null) {
            $this->api->sendMessage($chatId, 'سیستم عامل نامعتبر یا غیرفعال است.');

            return;
        }

        $plan = ProviderPlan::query()->where('id', $planId)->first();
        $location = ProviderLocation::query()->where('id', $locationId)->first();

        if ($plan === null || $location === null) {
            $this->api->sendMessage($chatId, 'اطلاعات نامعتبر. لطفاً دوباره شروع کنید.');

            return;
        }

        $this->state->update($telegramUserId, ['image_id' => $imageId]);

        // Get product and compute price
        $product = Product::query()
            ->where('provider_plan_id', $planId)
            ->where('billing_mode', $mode)
            ->where('status', 'active')
            ->where('enabled', true)
            ->first();

        if ($product === null) {
            $this->api->sendMessage($chatId, 'محصول یافت نشد.');

            return;
        }

        $pricingService = app(PricingService::class);
        $price = $pricingService->compute($plan, $product);
        $orderTotal = $pricingService->orderTotalToman($price, $product);

        if (BillingMode::tryFrom($mode)?->isHourly()) {
            $orderTotal = $this->billing->fundingAmount(
                $this->users->findByTelegramId($telegramUserId),
                (int) $price['hourly_price'],
            );
        }

        $priceDisplay = number_format($orderTotal).' تومان';
        $walletDisplay = '-';
        $capDisplay = '-';

        if (BillingMode::tryFrom($mode)?->isHourly()) {
            $user = $this->users->findByTelegramId($telegramUserId);
            $balance = $user->wallet->balance_toman ?? 0;
            $walletDisplay = number_format($balance).' تومان';
        }

        if ($mode === 'hourly_capped') {
            $capDisplay = number_format($product->monthly_cap_toman ?? 0).' تومان';
        }

        $details = [
            'location_name' => $location->name,
            'vcpu' => $plan->vcpu,
            'ram_mb_label' => $plan->ram_mb >= 1024 ? round($plan->ram_mb / 1024).' GB' : $plan->ram_mb.' MB',
            'disk_gb' => $plan->disk_gb,
            'image_name' => $image->name,
            'billing_mode' => $mode,
            'price_display' => $priceDisplay,
            'wallet_display' => $walletDisplay,
            'cap_display' => $capDisplay,
        ];

        $text = $this->menus->confirmPurchase($details);
        $buttons = $this->menus->confirmButtons($mode, $locationId, $planId, $imageId);

        $this->api->editMessageText($chatId, $messageId, $text, [
            'reply_markup' => ['inline_keyboard' => $buttons],
        ]);
    }

    private function confirmAndPay(int $chatId, int $messageId, int $telegramUserId, array $route): void
    {
        $mode = $route[2] ?? '';
        $locationId = (int) ($route[3] ?? 0);
        $planId = (int) ($route[4] ?? 0);
        $imageId = (int) ($route[5] ?? 0);

        // Idempotency guard: prevent duplicate confirm callbacks from creating
        // multiple orders. Cache::add is atomic — only the first caller wins.
        $idempotencyKey = "buy-confirm:{$telegramUserId}:{$mode}:{$planId}:{$imageId}";

        if (! Cache::add($idempotencyKey, true, 120)) {
            $this->api->sendMessage($chatId, '⏳ سفارش شما در حال پردازش است. لطفاً صبر کنید.');

            return;
        }

        $user = $this->users->findByTelegramId($telegramUserId);

        if ($user === null) {
            $this->api->sendMessage($chatId, '❌ کاربر یافت نشد.');

            return;
        }

        // Server-side validation (UI is not the security boundary)
        $product = Product::query()
            ->where('provider_plan_id', $planId)
            ->where('billing_mode', $mode)
            ->where('status', 'active')
            ->where('enabled', true)
            ->first();

        if ($product === null) {
            $this->api->sendMessage($chatId, '❌ محصول نامعتبر یا غیرفعال است.');
            $this->state->clear($telegramUserId);

            return;
        }

        $plan = ProviderPlan::query()->where('id', $planId)->where('enabled', true)->first();
        $location = ProviderLocation::query()->where('id', $locationId)->where('enabled', true)->first();
        $image = ProviderImage::query()->where('id', $imageId)->where('enabled', true)->whereNull('deprecated')->first();

        if ($plan === null || $location === null || $image === null) {
            $this->api->sendMessage($chatId, '❌ منبع نامعتبر یا غیرفعال است.');
            $this->state->clear($telegramUserId);

            return;
        }

        try {
            $order = $this->orders->place($user, $product);
            $invoice = $this->orders->createInvoice($order, 'manual');
            $payment = $this->payments->start($invoice, 'manual');
            $this->payments->confirm($payment, ['approved' => true], $user);
            $this->payments->provision($order->fresh());

            $this->api->editMessageText($chatId, $messageId, "⏳ <b>در حال ساخت سرور شما...</b>\n\nلطفاً صبر کنید.", []);

            $this->state->clear($telegramUserId);
        } catch (\Throwable $e) {
            $this->api->sendMessage($chatId, '❌ خطایی در پردازش سفارش رخ داد. لطفاً دوباره تلاش کنید.');
            $this->state->clear($telegramUserId);
        }
    }
}
