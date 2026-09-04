<?php

declare(strict_types=1);

namespace App\Providers\Filament;

use App\Filament\Pages\TwoFactorSetup;
use App\Http\Middleware\RequireTwoFactorEnrolment;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

/**
 * The staff admin panel.
 *
 * The path is the ordinary /admin. A secret URL is not a security control, and
 * treating one as such tends to replace the controls that are. Access is
 * decided server-side: User::canAccessPanel() requires an active account
 * holding a privileged role, and the middleware below requires a confirmed
 * second factor.
 *
 * Operational resources, dashboards and actions are a later phase. This panel
 * exists now so that privileged authentication is real and testable.
 */
class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->login()
            ->colors(['primary' => Color::Slate])
            ->pages([
                Dashboard::class,
                // Registered on the panel so an administrator who has not yet
                // enrolled has somewhere the middleware can send them.
                TwoFactorSetup::class,
            ])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
                // After authentication, so an unenrolled administrator is
                // redirected to enrolment rather than to the login page.
                RequireTwoFactorEnrolment::class,
            ]);
    }
}
