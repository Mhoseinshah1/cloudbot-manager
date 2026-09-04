<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Audit\AuditEvent;
use App\Audit\AuditRecorder;
use App\Auth\TwoFactor\TwoFactorAuthenticationService;
use App\Auth\TwoFactor\TwoFactorSession;
use App\Models\User;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;

/**
 * Where an administrator enrols their second factor.
 *
 * Reachable while the rest of the panel is not, because an administrator who
 * has just been created has no second factor yet and needs somewhere to set
 * one up.
 *
 * @property-read string|null $secret
 */
class TwoFactorSetup extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-shield-check';

    protected static ?string $title = 'Two-factor authentication';

    protected static string $view = 'filament.pages.two-factor-setup';

    /** The pending secret, shown once during enrolment. */
    public ?string $secret = null;

    public string $code = '';

    /** @var list<string> */
    public array $recoveryCodes = [];

    public bool $confirmed = false;

    public function mount(): void
    {
        $user = $this->admin();

        $this->confirmed = $user->hasConfirmedTwoFactor();

        // Re-enrolment would let a stolen password swap in a new device and
        // defeat the second factor, so an enrolled account is sent to the
        // challenge instead. Resetting a lost device is an operator action.
        if ($this->confirmed && ! app(TwoFactorSession::class)->isVerifiedFor($user)) {
            $this->redirect(TwoFactorChallenge::getUrl());

            return;
        }

        if (! $this->confirmed) {
            $this->secret = app(TwoFactorAuthenticationService::class)->startEnrolment($user);

            app(AuditRecorder::class)->record(
                AuditEvent::TwoFactorEnrolmentStarted,
                actor: $user,
                subject: $user,
            );
        }
    }

    /**
     * The otpauth URI rendered as an inline SVG.
     *
     * Inline rather than a stored file: the URI contains the secret, and a
     * generated image on disk would be a copy of it outside the encrypted
     * column.
     */
    public function qrCodeSvg(): ?string
    {
        if ($this->secret === null) {
            return null;
        }

        $uri = app(TwoFactorAuthenticationService::class)
            ->provisioningUri($this->admin(), $this->secret);

        $writer = new Writer(new ImageRenderer(new RendererStyle(220), new SvgImageBackEnd));

        return $writer->writeString($uri);
    }

    /**
     * Verify the submitted code and finish enrolment.
     */
    public function confirm(): void
    {
        $user = $this->admin();

        $codes = app(TwoFactorAuthenticationService::class)->confirm($user, $this->code);

        $this->code = '';

        if ($codes === null) {
            Notification::make()
                ->title('That code was not accepted.')
                ->body('Check your authenticator app and try the current code.')
                ->danger()
                ->send();

            return;
        }

        app(AuditRecorder::class)->record(
            AuditEvent::TwoFactorConfirmed,
            actor: $user,
            subject: $user,
        );

        // They just proved possession of the factor, which is exactly what the
        // challenge asks for, so this session does not need to ask again.
        app(TwoFactorSession::class)->markVerified($user);

        // Shown once. They are stored encrypted and cannot be displayed again.
        $this->recoveryCodes = $codes;
        $this->confirmed = true;
        $this->secret = null;

        Notification::make()
            ->title('Two-factor authentication is on.')
            ->success()
            ->send();
    }

    private function admin(): User
    {
        $user = Auth::user();

        // The panel middleware should have made this impossible; refusing
        // loudly is better than proceeding with an unknown identity.
        if (! $user instanceof User) {
            throw new AuthorizationException;
        }

        return $user;
    }
}
