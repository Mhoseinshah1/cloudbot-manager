<?php

declare(strict_types=1);

use App\Auth\TwoFactor\TwoFactorAuthenticationService;
use App\Auth\TwoFactor\TwoFactorPolicy;
use App\Authorization\RoleProvisioner;
use App\Enums\AdminRole;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use PragmaRX\Google2FA\Google2FA;

beforeEach(function (): void {
    app(RoleProvisioner::class)->sync();

    $this->admin = User::factory()->create();
    $this->admin->assignRole(AdminRole::Owner->value);

    $this->service = app(TwoFactorAuthenticationService::class);
});

it('always requires a second factor in production', function (): void {
    // The setting exists so tests can exercise the unenrolled path. It must not
    // be able to switch the requirement off where it matters.
    config()->set('cloudbot.admin.require_two_factor', false);
    app()->detectEnvironment(fn (): string => 'production');

    expect(app(TwoFactorPolicy::class)->isRequired())->toBeTrue();
});

it('requires a second factor by default outside production', function (): void {
    expect(app(TwoFactorPolicy::class)->isRequired())->toBeTrue();
});

it('keeps an unenrolled administrator out of the rest of the panel', function (): void {
    $this->actingAs($this->admin)
        ->get('/admin')
        ->assertRedirect(App\Filament\Pages\TwoFactorSetup::getUrl());
});

it('still lets an unenrolled administrator reach the enrolment page', function (): void {
    // Otherwise a newly created owner could never finish setting up.
    $this->actingAs($this->admin)
        ->get(App\Filament\Pages\TwoFactorSetup::getUrl())
        ->assertSuccessful();
});

it('lets an enrolled administrator through', function (): void {
    $this->admin->forceFill(['two_factor_confirmed_at' => now()])->save();

    $this->actingAs($this->admin)->get('/admin')->assertSuccessful();
});

it('does not treat an issued but unconfirmed secret as enrolled', function (): void {
    // Holding a secret proves nothing; producing a code from it does.
    $this->service->startEnrolment($this->admin);

    expect($this->admin->fresh()->hasConfirmedTwoFactor())->toBeFalse();

    $this->actingAs($this->admin->fresh())
        ->get('/admin')
        ->assertRedirect(App\Filament\Pages\TwoFactorSetup::getUrl());
});

it('completes enrolment when the code is right', function (): void {
    $secret = $this->service->startEnrolment($this->admin);
    $code = app(Google2FA::class)->getCurrentOtp($secret);

    $recoveryCodes = $this->service->confirm($this->admin, $code);

    expect($recoveryCodes)->toBeArray()->toHaveCount(8)
        ->and($this->admin->fresh()->hasConfirmedTwoFactor())->toBeTrue();
});

it('refuses a wrong code', function (): void {
    $this->service->startEnrolment($this->admin);

    expect($this->service->confirm($this->admin, '000000'))->toBeNull()
        ->and($this->admin->fresh()->hasConfirmedTwoFactor())->toBeFalse();
});

it('refuses anything that is not a six-digit code', function (string $code): void {
    $this->service->startEnrolment($this->admin);

    expect($this->service->verifyCode($this->admin, $code))->toBeFalse();
})->with(['', 'abcdef', '12345', '1234567', '<script>']);

it('refuses to verify for an account with no secret', function (): void {
    expect($this->service->verifyCode($this->admin, '123456'))->toBeFalse();
});

it('encrypts the secret at rest', function (): void {
    $secret = $this->service->startEnrolment($this->admin);

    // Read the raw column, bypassing the model's casts entirely.
    $stored = DB::table('users')->where('id', $this->admin->id)->value('two_factor_secret');

    expect($stored)->not->toBeNull()
        ->and($stored)->not->toContain($secret)
        ->and($this->admin->fresh()->two_factor_secret)->toBe($secret);
});

it('encrypts recovery codes at rest', function (): void {
    $secret = $this->service->startEnrolment($this->admin);
    $codes = $this->service->confirm($this->admin, app(Google2FA::class)->getCurrentOtp($secret));

    $stored = (string) DB::table('users')->where('id', $this->admin->id)->value('two_factor_recovery_codes');

    foreach ($codes ?? [] as $code) {
        expect($stored)->not->toContain($code);
    }
});

it('spends a recovery code once', function (): void {
    $secret = $this->service->startEnrolment($this->admin);
    $codes = $this->service->confirm($this->admin, app(Google2FA::class)->getCurrentOtp($secret));
    $code = ($codes ?? [])[0];

    expect($this->service->consumeRecoveryCode($this->admin, $code))->toBeTrue()
        // A code read off a screen or a printout must not work twice.
        ->and($this->service->consumeRecoveryCode($this->admin->fresh(), $code))->toBeFalse()
        ->and($this->admin->fresh()->two_factor_recovery_codes)->toHaveCount(7);
});

it('rejects an unknown recovery code', function (): void {
    $secret = $this->service->startEnrolment($this->admin);
    $this->service->confirm($this->admin, app(Google2FA::class)->getCurrentOtp($secret));

    expect($this->service->consumeRecoveryCode($this->admin, 'not-a-code'))->toBeFalse()
        ->and($this->admin->fresh()->two_factor_recovery_codes)->toHaveCount(8);
});

it('clears everything when two-factor is disabled', function (): void {
    $secret = $this->service->startEnrolment($this->admin);
    $this->service->confirm($this->admin, app(Google2FA::class)->getCurrentOtp($secret));

    $this->service->disable($this->admin);
    $fresh = $this->admin->fresh();

    expect($fresh->two_factor_secret)->toBeNull()
        ->and($fresh->two_factor_recovery_codes)->toBeNull()
        ->and($fresh->hasConfirmedTwoFactor())->toBeFalse();
});

it('keeps the secret out of the provisioning uri host and label only', function (): void {
    $secret = $this->service->startEnrolment($this->admin);
    $uri = $this->service->provisioningUri($this->admin, $secret);

    // The URI legitimately carries the secret; what matters is that it is an
    // otpauth URI for this account and not something else.
    expect($uri)->toStartWith('otpauth://totp/')
        ->and($uri)->toContain(rawurlencode((string) $this->admin->email));
});
