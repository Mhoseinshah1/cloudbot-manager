<?php

namespace App\Providers;

use App\Cloud\Hetzner\HetznerCredentialResolver;
use App\Cloud\Hetzner\HetznerCredentials;

use App\Auth\TwoFactor\TwoFactorSession;
use Illuminate\Auth\Events\Logout;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // The Hetzner transport depends on the credential question, not on the
        // answer, so the token stays in one class and a test can drive the
        // adapter against a faked endpoint without a provider row.
        $this->app->bind(HetznerCredentials::class, HetznerCredentialResolver::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Signing out drops the proof of second factor with everything else.
        // Filament already invalidates the session on logout; clearing the
        // state explicitly means the guarantee does not depend on that.
        Event::listen(Logout::class, function (): void {
            app(TwoFactorSession::class)->forget();
        });
    }
}
