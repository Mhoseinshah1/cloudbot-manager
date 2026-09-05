<?php

declare(strict_types=1);

use App\Enums\UserCreatedVia;
use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;

it('creates a telegram customer without an email or a password', function (): void {
    // The bot is the only customer interface, so most accounts will never have
    // either. Requiring them would make the product impossible.
    $user = User::factory()->fromTelegram()->create();

    expect($user->email)->toBeNull()
        ->and($user->password)->toBeNull()
        ->and($user->created_via)->toBe(UserCreatedVia::Telegram)
        ->and($user->status)->toBe(UserStatus::Active);
});

it('allows many customers to have no email at once', function (): void {
    // A unique index on a nullable column must still permit repeated NULLs,
    // or the second Telegram customer could never be created.
    User::factory()->fromTelegram()->count(3)->create();

    expect(User::query()->whereNull('email')->count())->toBe(3);
});

it('rejects a duplicate email', function (): void {
    User::factory()->create(['email' => 'owner@example.test']);

    expect(fn () => User::factory()->create(['email' => 'owner@example.test']))
        ->toThrow(QueryException::class);
});

it('starts every wallet at zero', function (): void {
    expect(User::factory()->create()->wallet_balance_toman)->toBe(0);
});

it('stores wallet balances as bigint', function (): void {
    // Toman amounts pass 2^31 in ordinary use, so a 32-bit column would
    // overflow on a normal balance.
    $amount = 9_000_000_000_000;

    $user = User::factory()->create();
    DB::table('users')->where('id', $user->id)->update(['wallet_balance_toman' => $amount]);

    expect($user->fresh()->wallet_balance_toman)->toBe($amount);
});

it('refuses to let a wallet balance go negative', function (): void {
    // The database is the floor beneath the wallet service that arrives later.
    $user = User::factory()->create();

    expect(fn () => DB::table('users')->where('id', $user->id)->update(['wallet_balance_toman' => -1]))
        ->toThrow(QueryException::class);
});

it('cannot have its wallet balance mass assigned', function (): void {
    // Money moves through the wallet service under a lock, never through a
    // stray key in a request payload.
    $user = User::factory()->create();

    $user->fill(['wallet_balance_toman' => 500_000]);
    $user->save();

    expect($user->fresh()->wallet_balance_toman)->toBe(0);
});

it('rejects a status the application does not define', function (): void {
    $user = User::factory()->create();

    expect(fn () => DB::table('users')->where('id', $user->id)->update(['status' => 'vip']))
        ->toThrow(QueryException::class);
});

it('rejects an origin the application does not define', function (): void {
    $user = User::factory()->create();

    expect(fn () => DB::table('users')->where('id', $user->id)->update(['created_via' => 'carrier-pigeon']))
        ->toThrow(QueryException::class);
});

it('knows which statuses may authenticate', function (): void {
    expect(User::factory()->create()->isActive())->toBeTrue()
        ->and(User::factory()->suspended()->create()->isActive())->toBeFalse()
        ->and(User::factory()->banned()->create()->isActive())->toBeFalse();
});

it('hashes the password', function (): void {
    $user = User::factory()->create(['password' => 'a-real-password-here']);

    expect($user->password)->not->toBe('a-real-password-here')
        ->and(Hash::check('a-real-password-here', (string) $user->password))->toBeTrue();
});

it('keeps credentials out of a serialised user', function (): void {
    $user = User::factory()->withTwoFactor()->create();

    $serialised = $user->toArray();

    expect($serialised)->not->toHaveKey('password')
        ->and($serialised)->not->toHaveKey('two_factor_secret')
        ->and($serialised)->not->toHaveKey('two_factor_recovery_codes')
        ->and($serialised)->not->toHaveKey('remember_token');
});

it('has no is_admin column', function (): void {
    // Being privileged is holding a role. A boolean here would be a second,
    // competing answer to the same question.
    expect(Schema::hasColumn('users', 'is_admin'))->toBeFalse();
});
