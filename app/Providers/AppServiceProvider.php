<?php

namespace App\Providers;

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
        //
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
