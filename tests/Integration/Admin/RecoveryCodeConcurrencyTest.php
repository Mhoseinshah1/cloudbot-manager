<?php

declare(strict_types=1);

use App\Auth\TwoFactor\TwoFactorAuthenticationService;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use PragmaRX\Google2FA\Google2FA;

/**
 * A recovery code is a single-use credential, so two requests arriving with the
 * same one must not both succeed.
 */
beforeEach(function (): void {
    $this->service = app(TwoFactorAuthenticationService::class);

    $this->admin = User::factory()->create();
    $secret = $this->service->startEnrolment($this->admin);
    $this->codes = $this->service->confirm($this->admin, app(Google2FA::class)->getCurrentOtp($secret));
    $this->admin->refresh();
});

it('reads the codes from the locked row, not from the caller instance', function (): void {
    // This is the shape of the race: two requests each load the user, so each
    // holds its own copy of the list showing the code unused. If consumption
    // trusted that copy, both would succeed and the code would work twice.
    $requestOne = User::query()->findOrFail($this->admin->id);
    $requestTwo = User::query()->findOrFail($this->admin->id);

    $code = $this->codes[0];

    expect($requestOne->two_factor_recovery_codes)->toContain($code)
        ->and($requestTwo->two_factor_recovery_codes)->toContain($code);

    expect($this->service->consumeRecoveryCode($requestOne, $code))->toBeTrue();

    // The second instance is now stale and still shows the code as available,
    // yet consumption must refuse it because it re-reads under the lock.
    expect($this->service->consumeRecoveryCode($requestTwo, $code))->toBeFalse()
        ->and($this->admin->fresh()->two_factor_recovery_codes)->toHaveCount(7);
});

it('takes a row lock while consuming', function (): void {
    // Proves the guarantee is the database's, not a lucky ordering: the query
    // that reads the codes is a locking read.
    $queries = [];
    DB::listen(function ($query) use (&$queries): void {
        $queries[] = $query->sql;
    });

    $this->service->consumeRecoveryCode($this->admin, $this->codes[0]);

    $locking = array_filter($queries, static fn (string $sql): bool => str_contains($sql, 'for update'));

    expect($locking)->not->toBeEmpty();
});

it('consumes each code exactly once across many attempts', function (): void {
    // Every code spent once, every code refused the second time, and the list
    // ends empty rather than partially consumed.
    foreach ($this->codes as $code) {
        expect($this->service->consumeRecoveryCode($this->admin->fresh(), $code))->toBeTrue();
    }

    foreach ($this->codes as $code) {
        expect($this->service->consumeRecoveryCode($this->admin->fresh(), $code))->toBeFalse();
    }

    expect($this->admin->fresh()->two_factor_recovery_codes)->toBe([]);
});

it('leaves the other codes usable after one is spent', function (): void {
    $this->service->consumeRecoveryCode($this->admin, $this->codes[0]);

    expect($this->service->consumeRecoveryCode($this->admin->fresh(), $this->codes[1]))->toBeTrue()
        ->and($this->admin->fresh()->two_factor_recovery_codes)->toHaveCount(6);
});
