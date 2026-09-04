<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Auth\TwoFactor\TwoFactorPolicy;
use App\Auth\TwoFactor\TwoFactorSession;
use App\Filament\Pages\TwoFactorChallenge;
use App\Filament\Pages\TwoFactorSetup;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Holds a password-authenticated administrator at the second factor.
 *
 * A correct password establishes who someone claims to be; it does not by
 * itself grant privileged access. Until this session has passed a challenge,
 * the only reachable pages are the one that completes it and logout.
 *
 * The check is per session rather than per request: an administrator proves
 * possession once when they sign in, not on every page load.
 *
 * This runs on the panel's page routes. Livewire posts its component updates to
 * its own endpoint, which this does not cover, and deliberately so: gating that
 * endpoint on the referring page would rest the decision on a header the caller
 * controls. What keeps a password-only session out of a protected component is
 * that it can never obtain that component's snapshot in the first place — the
 * page that would issue one is blocked here, and Livewire rejects a snapshot
 * whose checksum it did not sign.
 */
final class RequireTwoFactorAuthentication
{
    public function __construct(
        private readonly TwoFactorPolicy $policy,
        private readonly TwoFactorSession $twoFactorSession,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->policy->isRequired()) {
            return $next($request);
        }

        $user = $request->user();

        // Authentication itself is another middleware's job.
        if (! $user instanceof User) {
            return $next($request);
        }

        if ($this->twoFactorSession->isVerifiedFor($user)) {
            return $next($request);
        }

        // Always available: someone who cannot complete the challenge must
        // still be able to end the session.
        if ($this->isLogout($request)) {
            return $next($request);
        }

        // An enrolled administrator answers a challenge. They are deliberately
        // not allowed into enrolment, which would let a stolen password
        // register a new device and defeat the second factor entirely.
        $destination = $user->hasConfirmedTwoFactor()
            ? TwoFactorChallenge::getUrl()
            : TwoFactorSetup::getUrl();

        // Already there; letting it through avoids redirecting a page to itself.
        if ($request->fullUrlIs($destination.'*')) {
            return $next($request);
        }

        return redirect()->to($destination);
    }

    private function isLogout(Request $request): bool
    {
        return $request->routeIs('filament.admin.auth.logout');
    }
}
