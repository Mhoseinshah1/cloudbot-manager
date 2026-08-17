<?php

namespace App\Providers;

use App\Providers\Payment\ManualGateway;
use App\Services\AuditService;
use App\Services\OrderService;
use App\Services\PaymentManager;
use App\Services\PaymentService;
use App\Services\PricingService;
use App\Services\ProviderManager;
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

        $this->app->singleton(PaymentManager::class, function () {
            return new PaymentManager([
                'manual' => ManualGateway::class,
                // 'zarinpal' => ZarinpalGateway::class, // Phase 5
            ]);
        });
    }

    public function boot(): void
    {
        //
    }
}
