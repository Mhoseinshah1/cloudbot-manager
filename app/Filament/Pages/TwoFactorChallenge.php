<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Audit\AuditEvent;
use App\Audit\AuditRecorder;
use App\Auth\TwoFactor\TwoFactorAuthenticationService;
use App\Auth\TwoFactor\TwoFactorSession;
use App\Models\User;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;

/**
 * The second step of privileged sign-in.
 *
 * A correct password gets an administrator here and no further. Until a code
 * from their authenticator, or one of their recovery codes, is accepted, the
 * session holds no privileged access at all.
 */
class TwoFactorChallenge extends Page
{
    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $title = 'Two-factor authentication';

    protected static string $view = 'filament.pages.two-factor-challenge';

    public string $code = '';

    /**
     * Attempts allowed before the account is asked to wait.
     *
     * Enough to absorb a mistyped code or a phone whose clock has drifted, few
     * enough that guessing a six-digit code is not worth attempting.
     */
    private const MAX_ATTEMPTS = 5;

    private const DECAY_SECONDS = 60;

    public function mount(): void
    {
        $user = $this->admin();

        // Nothing to challenge: an unenrolled account belongs in enrolment, and
        // an already-verified session has no reason to be here.
        if (! $user->hasConfirmedTwoFactor()) {
            $this->redirect(TwoFactorSetup::getUrl());

            return;
        }

        if (app(TwoFactorSession::class)->isVerifiedFor($user)) {
            $this->redirect(filament()->getUrl());
        }
    }

    public function submit(): void
    {
        $user = $this->admin();
        $key = $this->rateLimiterKey($user);

        if (RateLimiter::tooManyAttempts($key, self::MAX_ATTEMPTS)) {
            $this->code = '';

            Notification::make()
                ->title('Too many attempts.')
                ->body(sprintf('Try again in %d seconds.', RateLimiter::availableIn($key)))
                ->danger()
                ->send();

            return;
        }

        $submitted = $this->code;
        // Cleared before anything else can fail: the code must not survive in
        // component state that gets serialised back to the browser.
        $this->code = '';

        if (! app(TwoFactorAuthenticationService::class)->verifyChallenge($user, $submitted)) {
            RateLimiter::hit($key, self::DECAY_SECONDS);

            // One generic message for a wrong code, a wrong recovery code and a
            // malformed one. Saying which would help someone narrow it down.
            Notification::make()
                ->title('That code was not accepted.')
                ->danger()
                ->send();

            return;
        }

        RateLimiter::clear($key);

        app(TwoFactorSession::class)->markVerified($user);

        app(AuditRecorder::class)->record(
            AuditEvent::TwoFactorChallengePassed,
            actor: $user,
            subject: $user,
        );

        $this->redirect(filament()->getUrl());
    }

    /**
     * Scoped to the account, so one administrator's mistyping cannot lock
     * another out, and so clearing it is not a global reset.
     */
    private function rateLimiterKey(User $user): string
    {
        return 'two-factor-challenge:'.$user->getKey();
    }

    private function admin(): User
    {
        $user = Auth::user();

        if (! $user instanceof User) {
            throw new AuthorizationException;
        }

        return $user;
    }
}
