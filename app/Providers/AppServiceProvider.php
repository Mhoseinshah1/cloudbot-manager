<?php

namespace App\Providers;

use App\Events\LowBalanceWarningTriggered;
use App\Providers\Payment\ManualGateway;
use App\Services\AuditService;
use App\Services\HourlyBillingService;
use App\Services\OrderService;
use App\Services\PaymentManager;
use App\Services\PaymentService;
use App\Services\PricingService;
use App\Services\ProviderManager;
use App\Services\Telegram\TelegramNotificationService;
use App\Services\WalletService;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ProviderManager::class);
        $this->app->singleton(AuditService::class);
        $this->app->singleton(PricingService::class);
        $this->app->singleton(OrderService::class);
        $this->app->singleton(PaymentService::class);
        $this->app->singleton(WalletService::class);
        $this->app->singleton(HourlyBillingService::class);

        $this->app->singleton(PaymentManager::class, function () {
            return new PaymentManager([
                'manual' => ManualGateway::class,
                // 'zarinpal' => ZarinpalGateway::class, // Phase 5
            ]);
        });
    }

    public function boot(): void
    {
        // Wire Telegram notifications for billing core events.
        Event::listen(LowBalanceWarningTriggered::class, fn (LowBalanceWarningTriggered $event) => app(TelegramNotificationService::class)->handleLowBalanceWarning($event));
    }
}
