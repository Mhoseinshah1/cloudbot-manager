<?php

namespace App\Services\Telegram\Flows;

use App\Enums\BillingMode;
use App\Models\Product;
use App\Models\ProviderImage;
use App\Models\ProviderLocation;
use App\Models\ProviderPlan;
use App\Models\ProviderPlanPrice;
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

        $planIds = Product::query()
            ->where('billing_mode', $mode)
            ->where('status', Product::STATUS_ACTIVE)
            ->where('enabled', true)
            ->whereHas('providerPlan', fn ($query) => $query->where('enabled', true))
            ->pluck('provider_plan_id');

        $locationIds = ProviderPlanPrice::query()
            ->whereIn('provider_plan_id', $planIds)
            ->where('deprecated', false)
            ->pluck('provider_location_id')
            ->unique();

        $locations = ProviderLocation::query()
            ->whereIn('id', $locationIds)
            ->where('enabled', true)
            ->get()
            ->map(fn (ProviderLocation $location) => [
                'id' => $location->id,
                'name' => $location->name,
                'country_code' => $location->country_code,
            ])
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

        if (! $this->validBuyState($telegramUserId, $mode)) {
            $this->sendExpiredState($chatId);

            return;
        }

        $location = ProviderLocation::query()
            ->whereKey($locationId)
            ->where('enabled', true)
            ->first();

        if ($location === null) {
            $this->api->sendMessage($chatId, 'لوکیشن نامعتبر یا غیرفعال است.');

            return;
        }

        $products = Product::query()
            ->where('billing_mode', $mode)
            ->where('status', Product::STATUS_ACTIVE)
            ->where('enabled', true)
            ->whereHas('providerPlan', function ($plan) use ($locationId) {
                $plan->where('enabled', true)
                    ->whereHas('prices', function ($price) use ($locationId) {
                        $price->where('provider_location_id', $locationId)
                            ->where('deprecated', false);
                    });
            })
            ->get();

        $planIds = $products->pluck('provider_plan_id')->unique()->values();
        $planMap = ProviderPlan::query()
            ->whereIn('id', $planIds)
            ->where('enabled', true)
            ->get()
            ->keyBy('id');

        $plans = $products
            ->map(fn (Product $product) => $planMap->get($product->provider_plan_id))
            ->filter()
            ->unique('id')
            ->values()
            ->map(fn (ProviderPlan $plan) => [
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

        $this->state->update($telegramUserId, ['location_id' => $locationId]);

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

        if (! $this->validBuyState($telegramUserId, $mode, $locationId)) {
            $this->sendExpiredState($chatId);

            return;
        }

        $product = $this->availableProduct($mode, $locationId, $planId);
        $plan = ProviderPlan::query()->whereKey($planId)->where('enabled', true)->first();

        if ($product === null || $plan === null || $plan->provider_id !== $product->provider_id) {
            $this->api->sendMessage($chatId, 'پلن برای این لوکیشن نامعتبر یا غیرفعال است.');

            return;
        }

        $images = ProviderImage::query()
            ->where('provider_id', $plan->provider_id)
            ->where('enabled', true)
            ->whereNull('deprecated')
            ->when($plan->architecture !== null, function ($query) use ($plan) {
                $query->where(function ($architecture) use ($plan) {
                    $architecture->whereNull('architecture')
                        ->orWhere('architecture', $plan->architecture);
                });
            })
            ->get()
            ->map(fn (ProviderImage $image) => ['id' => $image->id, 'name' => $image->name])
            ->toArray();

        if ($images === []) {
            $this->api->sendMessage($chatId, '⚠️ سیستم عاملی برای این پلن موجود نیست.');

            return;
        }

        $this->state->update($telegramUserId, ['plan_id' => $planId]);

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

        if (! $this->validBuyState($telegramUserId, $mode, $locationId, $planId)) {
            $this->sendExpiredState($chatId);

            return;
        }

        $product = $this->availableProduct($mode, $locationId, $planId);
        $plan = ProviderPlan::query()->whereKey($planId)->where('enabled', true)->first();
        $location = ProviderLocation::query()->whereKey($locationId)->where('enabled', true)->first();

        if ($product === null || $plan === null || $location === null || $location->provider_id !== $plan->provider_id) {
            $this->api->sendMessage($chatId, 'اطلاعات نامعتبر. لطفاً دوباره شروع کنید.');

            return;
        }

        $image = ProviderImage::query()
            ->whereKey($imageId)
            ->where('provider_id', $plan->provider_id)
            ->where('enabled', true)
            ->whereNull('deprecated')
            ->first();

        if ($image === null || ($plan->architecture !== null && $image->architecture !== null && $plan->architecture !== $image->architecture)) {
            $this->api->sendMessage($chatId, 'سیستم عامل نامعتبر یا ناسازگار است.');

            return;
        }

        $this->state->update($telegramUserId, ['image_id' => $imageId]);

        $pricingService = app(PricingService::class);
        $price = $pricingService->compute($plan, $product);
        $orderTotal = $pricingService->orderTotalToman($price, $product);

        if (BillingMode::tryFrom($mode)?->isHourly()) {
            $user = $this->users->findByTelegramId($telegramUserId);
            if ($user === null) {
                $this->api->sendMessage($chatId, '❌ کاربر یافت نشد.');

                return;
            }

            $orderTotal = $this->billing->fundingAmount($user, (int) $price['hourly_price']);
        }

        $priceDisplay = number_format($orderTotal).' تومان';
        $walletDisplay = '-';
        $capDisplay = '-';

        if (BillingMode::tryFrom($mode)?->isHourly()) {
            $user = $this->users->findByTelegramId($telegramUserId);
            $balance = $user?->wallet?->balance_toman ?? 0;
            $walletDisplay = number_format($balance).' تومان';
        }

        if ($mode === BillingMode::HourlyCapped->value) {
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

        $idempotencyKey = "buy-confirm:{$telegramUserId}:{$mode}:{$locationId}:{$planId}:{$imageId}";

        if (! Cache::add($idempotencyKey, true, 600)) {
            $this->api->sendMessage($chatId, '⏳ سفارش شما در حال پردازش است. لطفاً صبر کنید.');

            return;
        }

        try {
            if (! $this->validBuyState($telegramUserId, $mode, $locationId, $planId, $imageId)) {
                $this->sendExpiredState($chatId);

                return;
            }

            $user = $this->users->findByTelegramId($telegramUserId);

            if ($user === null) {
                $this->api->sendMessage($chatId, '❌ کاربر یافت نشد.');

                return;
            }

            $product = $this->availableProduct($mode, $locationId, $planId);
            $plan = ProviderPlan::query()->whereKey($planId)->where('enabled', true)->first();
            $location = ProviderLocation::query()->whereKey($locationId)->where('enabled', true)->first();
            $image = ProviderImage::query()
                ->whereKey($imageId)
                ->where('enabled', true)
                ->whereNull('deprecated')
                ->first();

            if (
                $product === null || $plan === null || $location === null || $image === null
                || $plan->provider_id !== $product->provider_id
                || $location->provider_id !== $plan->provider_id
                || $image->provider_id !== $plan->provider_id
                || ($plan->architecture !== null && $image->architecture !== null && $plan->architecture !== $image->architecture)
            ) {
                $this->api->sendMessage($chatId, '❌ منبع نامعتبر، غیرفعال یا ناسازگار است.');
                $this->state->clear($telegramUserId);

                return;
            }

            $order = $this->orders->place($user, $product, null, $locationId, $imageId);
            $invoice = $this->orders->createInvoice($order, 'manual');
            $payment = $this->payments->start($invoice, 'manual');
            $this->payments->confirm($payment, ['approved' => true], $user);
            $this->payments->provision($order->fresh());

            $this->api->editMessageText($chatId, $messageId, "⏳ <b>در حال ساخت سرور شما...</b>\n\nلطفاً صبر کنید.", []);
            $this->state->clear($telegramUserId);
        } catch (\Throwable) {
            Cache::forget($idempotencyKey);
            $this->api->sendMessage($chatId, '❌ خطایی در پردازش سفارش رخ داد. لطفاً دوباره تلاش کنید.');
            $this->state->clear($telegramUserId);
        }
    }

    private function availableProduct(string $mode, int $locationId, int $planId): ?Product
    {
        return Product::query()
            ->where('provider_plan_id', $planId)
            ->where('billing_mode', $mode)
            ->where('status', Product::STATUS_ACTIVE)
            ->where('enabled', true)
            ->whereHas('providerPlan', function ($plan) use ($locationId) {
                $plan->where('enabled', true)
                    ->whereHas('prices', function ($price) use ($locationId) {
                        $price->where('provider_location_id', $locationId)
                            ->where('deprecated', false);
                    });
            })
            ->first();
    }

    private function validBuyState(
        int $telegramUserId,
        string $mode,
        ?int $locationId = null,
        ?int $planId = null,
        ?int $imageId = null,
    ): bool {
        $state = $this->state->get($telegramUserId);

        if ($state === null || ($state['flow'] ?? null) !== 'buy' || ($state['billing_mode'] ?? null) !== $mode) {
            return false;
        }

        if ($locationId !== null && isset($state['location_id']) && (int) $state['location_id'] !== $locationId) {
            return false;
        }

        if ($planId !== null && isset($state['plan_id']) && (int) $state['plan_id'] !== $planId) {
            return false;
        }

        if ($imageId !== null && isset($state['image_id']) && (int) $state['image_id'] !== $imageId) {
            return false;
        }

        return true;
    }

    private function sendExpiredState(int $chatId): void
    {
        $this->api->sendMessage($chatId, '⏳ این مرحله منقضی یا نامعتبر شده است. لطفاً خرید را دوباره شروع کنید.');
    }
}
