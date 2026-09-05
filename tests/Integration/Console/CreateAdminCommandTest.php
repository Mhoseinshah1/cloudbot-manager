<?php

declare(strict_types=1);

use App\Audit\AuditEvent;
use App\Enums\AdminRole;
use App\Enums\UserCreatedVia;
use App\Enums\UserStatus;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

it('creates an owner account', function (): void {
    $this->artisan('app:create-admin', ['--name' => 'Ada', '--email' => 'ada@example.test'])
        ->expectsQuestion('Password', 'a-long-enough-password')
        ->expectsQuestion('Confirm password', 'a-long-enough-password')
        ->assertSuccessful();

    $admin = User::query()->where('email', 'ada@example.test')->sole();

    expect($admin->name)->toBe('Ada')
        ->and($admin->status)->toBe(UserStatus::Active)
        ->and($admin->created_via)->toBe(UserCreatedVia::Admin)
        ->and($admin->hasRole(AdminRole::Owner->value))->toBeTrue()
        ->and($admin->wallet_balance_toman)->toBe(0)
        ->and($admin->locale)->toBe(config('cloudbot.defaults.locale'))
        ->and($admin->timezone)->toBe(config('cloudbot.defaults.timezone'));
});

it('hashes the password and never echoes it', function (): void {
    $this->artisan('app:create-admin', ['--name' => 'Ada', '--email' => 'ada@example.test'])
        ->expectsQuestion('Password', 'a-long-enough-password')
        ->expectsQuestion('Confirm password', 'a-long-enough-password')
        ->doesntExpectOutputToContain('a-long-enough-password')
        ->assertSuccessful();

    $admin = User::query()->where('email', 'ada@example.test')->sole();

    expect($admin->password)->not->toBe('a-long-enough-password')
        ->and(Hash::check('a-long-enough-password', (string) $admin->password))->toBeTrue();
});

it('creates the account without a second factor, to be enrolled on first sign-in', function (): void {
    // The secret is generated server-side and shown once in the browser, so it
    // never passes through a terminal, shell history or a CI log.
    $this->artisan('app:create-admin', ['--name' => 'Ada', '--email' => 'ada@example.test'])
        ->expectsQuestion('Password', 'a-long-enough-password')
        ->expectsQuestion('Confirm password', 'a-long-enough-password')
        ->assertSuccessful();

    $admin = User::query()->where('email', 'ada@example.test')->sole();

    expect($admin->hasConfirmedTwoFactor())->toBeFalse()
        ->and($admin->two_factor_secret)->toBeNull();

    // And that account cannot use the panel until it enrols.
    $this->actingAs($admin)
        ->get('/admin')
        ->assertRedirect(App\Filament\Pages\TwoFactorSetup::getUrl());
});

it('refuses to touch an account that already exists', function (): void {
    // An installer re-run must never become a privilege escalation or a
    // password reset.
    $existing = User::factory()->create([
        'email' => 'ada@example.test',
        'password' => 'the-original-password',
    ]);
    $originalHash = $existing->password;

    $this->artisan('app:create-admin', ['--name' => 'Impostor', '--email' => 'ada@example.test'])
        ->expectsOutputToContain('already exists')
        ->assertFailed();

    $unchanged = $existing->fresh();

    expect($unchanged->password)->toBe($originalHash)
        ->and($unchanged->name)->not->toBe('Impostor')
        ->and($unchanged->hasRole(AdminRole::Owner->value))->toBeFalse()
        ->and(User::query()->where('email', 'ada@example.test')->count())->toBe(1);
});

it('refuses mismatched passwords', function (): void {
    $this->artisan('app:create-admin', ['--name' => 'Ada', '--email' => 'ada@example.test'])
        ->expectsQuestion('Password', 'a-long-enough-password')
        ->expectsQuestion('Confirm password', 'a-different-password')
        ->assertFailed();

    expect(User::query()->where('email', 'ada@example.test')->exists())->toBeFalse();
});

it('refuses a short password', function (): void {
    $this->artisan('app:create-admin', ['--name' => 'Ada', '--email' => 'ada@example.test'])
        ->expectsQuestion('Password', 'short')
        ->expectsQuestion('Confirm password', 'short')
        ->assertFailed();

    expect(User::query()->where('email', 'ada@example.test')->exists())->toBeFalse();
});

it('refuses an invalid email', function (): void {
    $this->artisan('app:create-admin', ['--name' => 'Ada', '--email' => 'not-an-email'])
        ->assertFailed();

    expect(User::query()->count())->toBe(0);
});

it('provisions the roles it needs', function (): void {
    // Works on a database where nothing has been seeded yet.
    $this->artisan('app:create-admin', ['--name' => 'Ada', '--email' => 'ada@example.test'])
        ->expectsQuestion('Password', 'a-long-enough-password')
        ->expectsQuestion('Confirm password', 'a-long-enough-password')
        ->assertSuccessful();

    expect(Spatie\Permission\Models\Role::query()->count())->toBe(3);
});

it('audits the creation without recording the password', function (): void {
    $this->artisan('app:create-admin', ['--name' => 'Ada', '--email' => 'ada@example.test'])
        ->expectsQuestion('Password', 'a-long-enough-password')
        ->expectsQuestion('Confirm password', 'a-long-enough-password')
        ->assertSuccessful();

    $entry = AuditLog::query()->where('event', AuditEvent::AdminCreated)->sole();

    expect($entry->actor_type)->toBe('console')
        ->and(json_encode($entry->metadata))->toContain('ada@example.test')
        ->and(json_encode($entry->metadata))->not->toContain('a-long-enough-password');
});
