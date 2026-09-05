<?php

declare(strict_types=1);

use App\Auth\TwoFactor\TwoFactorAuthenticationService;
use App\Auth\TwoFactor\TwoFactorSession;
use App\Authorization\RoleProvisioner;
use App\Enums\AdminRole;
use App\Filament\Pages\TwoFactorChallenge;
use App\Filament\Pages\TwoFactorSetup;
use App\Models\User;
use PragmaRX\Google2FA\Google2FA;

/**
 * What production must refuse, proved through real requests.
 *
 * Each of these runs with the environment set to production and the
 * configuration switch turned off, which is the state an operator could reach
 * by accident or an attacker by tampering with configuration.
 */
beforeEach(function (): void {
    app(RoleProvisioner::class)->sync();

    app()->detectEnvironment(fn (): string => 'production');
    config()->set('cloudbot.admin.require_two_factor', false);

    $this->service = app(TwoFactorAuthenticationService::class);
});

function enrolledOwner(TwoFactorAuthenticationService $service): array
{
    $admin = User::factory()->create();
    $admin->assignRole(AdminRole::Owner->value);

    $secret = $service->startEnrolment($admin);
    $service->confirm($admin, app(Google2FA::class)->getCurrentOtp($secret));

    return [$admin->fresh(), $secret];
}

it('refuses a password-only session even with the requirement configured off', function (): void {
    // Configuration cannot disable the second factor in production.
    [$admin] = enrolledOwner($this->service);

    $this->actingAs($admin)
        ->get('/admin')
        ->assertRedirect(TwoFactorChallenge::getUrl());
});

it('admits the same administrator once the challenge is passed', function (): void {
    [$admin] = enrolledOwner($this->service);

    $this->actingAs($admin);
    app(TwoFactorSession::class)->markVerified($admin);

    $this->get('/admin')->assertSuccessful();
});

it('sends an unenrolled administrator to enrolment and nowhere else', function (): void {
    $admin = User::factory()->create();
    $admin->assignRole(AdminRole::Owner->value);

    $this->actingAs($admin)->get('/admin')->assertRedirect(TwoFactorSetup::getUrl());
    $this->actingAs($admin)->get(TwoFactorChallenge::getUrl())->assertRedirect(TwoFactorSetup::getUrl());
});

it('never admits an ordinary customer, second factor or not', function (): void {
    $customer = User::factory()->fromTelegram()->create();

    $this->actingAs($customer)->get('/admin')->assertForbidden();
});

it('never admits a suspended administrator who has passed a challenge', function (): void {
    // Standing is checked at the panel gate, so passing the factor does not
    // rescue an account that has been suspended.
    [$admin] = enrolledOwner($this->service);
    $admin->forceFill(['status' => App\Enums\UserStatus::Suspended])->save();

    $this->actingAs($admin->fresh());
    app(TwoFactorSession::class)->markVerified($admin->fresh());

    $this->get('/admin')->assertForbidden();
});

it('never admits a banned administrator who has passed a challenge', function (): void {
    [$admin] = enrolledOwner($this->service);
    $admin->forceFill(['status' => App\Enums\UserStatus::Banned])->save();

    $this->actingAs($admin->fresh());
    app(TwoFactorSession::class)->markVerified($admin->fresh());

    $this->get('/admin')->assertForbidden();
});

it('keeps the second factor secret out of the session', function (): void {
    // The session records who passed and when, never anything that could be
    // replayed to pass again.
    [$admin, $secret] = enrolledOwner($this->service);

    $this->actingAs($admin);
    app(TwoFactorSession::class)->markVerified($admin);

    $serialised = json_encode(session()->all());

    expect($serialised)->not->toContain($secret);

    foreach ($admin->two_factor_recovery_codes ?? [] as $code) {
        expect($serialised)->not->toContain($code);
    }
});
