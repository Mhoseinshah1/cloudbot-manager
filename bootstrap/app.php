<?php

use App\Http\Controllers\HealthController;
use App\Http\Controllers\Telegram\WebhookController;
use App\Http\Middleware\AssignRequestId;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        then: function (): void {
            // Registered outside the web group on purpose. Probes hit this
            // endpoint continuously; inside the web group each probe would
            // start a session and write a row to PostgreSQL.
            Route::get('/health', HealthController::class)->name('health');

            // Also outside the web group, and that is the CSRF exemption.
            //
            // Browser CSRF protection defends a session against a cross-site
            // form post. Telegram has no session and sends no cookie, so the
            // token check would reject every legitimate delivery while
            // protecting nothing. Rather than disabling the middleware — which
            // would weaken it for real browser traffic too — this route simply
            // never joins the group that applies it, exactly as /health does.
            //
            // What authenticates a delivery instead is the shared secret
            // Telegram echoes in a header, checked in constant time by the
            // controller. That is a stronger control than a CSRF token here: it
            // proves the sender knows something only Telegram was told.
            Route::post('/telegram/webhook', WebhookController::class)
                ->name('telegram.webhook');
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Global, so that queue-adjacent and future webhook routes are
        // correlated too, not just browser traffic.
        $middleware->append(AssignRequestId::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
