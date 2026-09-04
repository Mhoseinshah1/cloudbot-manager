<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Auth\TwoFactor\TwoFactorPolicy;
use App\Filament\Pages\TwoFactorSetup;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Keeps an administrator who has not enrolled a second factor out of the
 * panel, while still letting them reach the page that enrols one.
 *
 * Enforcement lives here rather than in canAccessPanel() because an
 * unenrolled administrator has to be able to load exactly one page. Denying at
 * the panel gate would lock them out with no way back in.
 */
final class RequireTwoFactorEnrolment
{
    public function __construct(private readonly TwoFactorPolicy $policy) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->policy->isRequired()) {
            return $next($request);
        }

        $user = $request->user();

        if (! $user instanceof User || $user->hasConfirmedTwoFactor()) {
            return $next($request);
        }

        $setupUrl = TwoFactorSetup::getUrl();

        // Without this the redirect would point at the page we are already on.
        if ($request->fullUrlIs($setupUrl.'*') || $request->routeIs('filament.admin.auth.logout')) {
            return $next($request);
        }

        return redirect()->to($setupUrl);
    }
}
