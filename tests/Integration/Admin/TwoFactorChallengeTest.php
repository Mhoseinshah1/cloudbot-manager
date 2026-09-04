<?php

declare(strict_types=1);

use App\Audit\AuditEvent;
use App\Auth\TwoFactor\TwoFactorAuthenticationService;
use App\Auth\TwoFactor\TwoFactorSession;
use App\Authorization\RoleProvisioner;
use App\Enums\AdminRole;
use App\Filament\Pages\TwoFactorChallenge;
use App\Filament\Pages\TwoFactorSetup;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;
use PragmaRX\Google2FA\Google2FA;

/**
 * The real sign-in flow.
 *
 * These tests never treat actingAs() as proof that a second factor was
 * supplied: actingAs establishes only the first factor, which is exactly the
 * state an attacker holding a stolen password would be in.
 */
beforeEach(function (): void {
    app(RoleProvisioner::class)->sync();

    $this->service = app(TwoFactorAuthenticationService::class);

    $this->admin = User::factory()->create();
    $this->admin->assignRole(AdminRole::Owner->value);

    // Enrolled, exactly as an established administrator would be.
    $this->secret = $this->service->startEnrolment($this->admin);
    $this->recoveryCodes = $this->service->confirm(
        $this->admin,
        app(Google2FA::class)->getCurrentOtp($this->secret),
    );
    $this->admin->refresh();
});

function currentOtpFor(string $secret): string
{
    return app(Google2FA::class)->getCurrentOtp($secret);
}

it('refuses the panel to a password-only session', function (): void {
    // The heart of the fix. Being enrolled is not the same as having proved it
    // in this session, and a stolen password must not be enough.
    $this->actingAs($this->admin)
        ->get('/admin')
        ->assertRedirect(TwoFactorChallenge::getUrl());
});

it('sends an enrolled administrator to the challenge, not to enrolment', function (): void {
    // Enrolment would let a stolen password register a new device.
    $this->actingAs($this->admin)
        ->get(TwoFactorSetup::getUrl())
        ->assertRedirect(TwoFactorChallenge::getUrl());
});

it('lets a password-only session reach the challenge itself', function (): void {
    $this->actingAs($this->admin)
        ->get(TwoFactorChallenge::getUrl())
        ->assertSuccessful();
});

it('grants access for the session when the code is correct', function (): void {
    $this->actingAs($this->admin);

    Livewire::test(TwoFactorChallenge::class)
        ->set('code', currentOtpFor($this->secret))
        ->call('submit')
        ->assertHasNoErrors();

    expect(app(TwoFactorSession::class)->isVerifiedFor($this->admin))->toBeTrue();

    $this->get('/admin')->assertSuccessful();
});

it('does not grant access for an incorrect code', function (): void {
    $this->actingAs($this->admin);

    Livewire::test(TwoFactorChallenge::class)
        ->set('code', '000000')
        ->call('submit');

    expect(app(TwoFactorSession::class)->isVerifiedFor($this->admin))->toBeFalse();

    $this->get('/admin')->assertRedirect(TwoFactorChallenge::getUrl());
});

it('does not grant access for malformed input', function (string $input): void {
    $this->actingAs($this->admin);

    Livewire::test(TwoFactorChallenge::class)
        ->set('code', $input)
        ->call('submit');

    expect(app(TwoFactorSession::class)->isVerifiedFor($this->admin))->toBeFalse();
})->with(['', '   ', 'abcdef', '12345', '1234567', '<script>alert(1)</script>', '000000 or 1=1']);

it('clears the submitted code from component state', function (): void {
    // The code must not linger in state that is serialised back to the browser.
    $this->actingAs($this->admin);

    Livewire::test(TwoFactorChallenge::class)
        ->set('code', currentOtpFor($this->secret))
        ->call('submit')
        ->assertSet('code', '');
});

it('accepts an unused recovery code', function (): void {
    $this->actingAs($this->admin);

    Livewire::test(TwoFactorChallenge::class)
        ->set('code', $this->recoveryCodes[0])
        ->call('submit');

    expect(app(TwoFactorSession::class)->isVerifiedFor($this->admin))->toBeTrue();
    $this->get('/admin')->assertSuccessful();
});

it('will not accept the same recovery code twice', function (): void {
    $code = $this->recoveryCodes[0];

    expect($this->service->verifyChallenge($this->admin, $code))->toBeTrue()
        ->and($this->service->verifyChallenge($this->admin->fresh(), $code))->toBeFalse()
        ->and($this->admin->fresh()->two_factor_recovery_codes)->toHaveCount(7);
});

it('requires the factor again on a new session', function (): void {
    $this->actingAs($this->admin);

    Livewire::test(TwoFactorChallenge::class)
        ->set('code', currentOtpFor($this->secret))
        ->call('submit');

    $this->get('/admin')->assertSuccessful();

    // Signing out and back in is a new session, and it owes the factor again.
    $this->post('/admin/logout');
    $this->flushSession();

    $this->actingAs($this->admin->fresh())
        ->get('/admin')
        ->assertRedirect(TwoFactorChallenge::getUrl());
});

it('drops the verified state on logout', function (): void {
    $this->actingAs($this->admin);

    Livewire::test(TwoFactorChallenge::class)
        ->set('code', currentOtpFor($this->secret))
        ->call('submit');

    expect(app(TwoFactorSession::class)->isVerifiedFor($this->admin))->toBeTrue();

    $this->post('/admin/logout');

    expect(app(TwoFactorSession::class)->isVerifiedFor($this->admin))->toBeFalse();
});

it('does not let one administrator inherit another verified session', function (): void {
    // The state names the account that earned it, so it cannot be reused by a
    // different user who lands on the same session.
    $this->actingAs($this->admin);

    Livewire::test(TwoFactorChallenge::class)
        ->set('code', currentOtpFor($this->secret))
        ->call('submit');

    $other = User::factory()->create();
    $other->assignRole(AdminRole::Owner->value);
    $this->service->confirm($other, currentOtpFor($this->service->startEnrolment($other)));

    expect(app(TwoFactorSession::class)->isVerifiedFor($other->fresh()))->toBeFalse();

    $this->actingAs($other->fresh())
        ->get('/admin')
        ->assertRedirect(TwoFactorChallenge::getUrl());
});

it('rate limits repeated failures without locking the account forever', function (): void {
    $this->actingAs($this->admin);

    for ($attempt = 0; $attempt < 5; $attempt++) {
        Livewire::test(TwoFactorChallenge::class)->set('code', '000000')->call('submit');
    }

    // The correct code is refused while the limiter is engaged...
    Livewire::test(TwoFactorChallenge::class)
        ->set('code', currentOtpFor($this->secret))
        ->call('submit');

    expect(app(TwoFactorSession::class)->isVerifiedFor($this->admin))->toBeFalse();

    // ...and the limit is a delay, not a permanent lockout.
    RateLimiter::clear('two-factor-challenge:'.$this->admin->getKey());

    Livewire::test(TwoFactorChallenge::class)
        ->set('code', currentOtpFor($this->secret))
        ->call('submit');

    expect(app(TwoFactorSession::class)->isVerifiedFor($this->admin))->toBeTrue();
});

it('audits a passed challenge without recording the code', function (): void {
    $this->actingAs($this->admin);
    $code = currentOtpFor($this->secret);

    Livewire::test(TwoFactorChallenge::class)->set('code', $code)->call('submit');

    $entry = AuditLog::query()->where('event', AuditEvent::TwoFactorChallengePassed)->sole();

    expect((int) $entry->actor_id)->toBe($this->admin->id)
        ->and(json_encode($entry->toArray()))->not->toContain($code);
});

it('does not audit a failed attempt', function (): void {
    // A mistyped code is not an event worth a permanent record; the rate
    // limiter is what responds to repeated failures.
    $this->actingAs($this->admin);

    Livewire::test(TwoFactorChallenge::class)->set('code', '000000')->call('submit');

    expect(AuditLog::query()->where('event', AuditEvent::TwoFactorChallengePassed)->count())->toBe(0);
});
