<?php

declare(strict_types=1);

namespace App\Auth\TwoFactor;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;

/**
 * TOTP enrolment and verification for privileged accounts.
 *
 * The algorithm comes from google2fa; none of it is written here. What this
 * class owns is the lifecycle: issuing a secret, confirming the administrator
 * can actually produce a code from it, and consuming recovery codes.
 *
 * Secrets and recovery codes are encrypted at rest by the model's casts, and
 * are never returned in exception messages or written to logs.
 */
final class TwoFactorAuthenticationService
{
    /**
     * How many 30-second steps either side of now are accepted.
     *
     * One step tolerates ordinary clock drift between the server and the
     * administrator's phone. Widening it lengthens the window in which a
     * captured code still works.
     */
    private const WINDOW = 1;

    private const RECOVERY_CODE_COUNT = 8;

    public function __construct(private readonly Google2FA $google2fa) {}

    /**
     * Begin enrolment: issue a secret but do not trust it yet.
     *
     * The account is not considered protected until confirm() proves the
     * administrator holds a device that generates matching codes. Storing an
     * unconfirmed secret is what lets them retry without starting over, and
     * leaving two_factor_confirmed_at null is what keeps them locked out of
     * everything else meanwhile.
     */
    public function startEnrolment(User $user): string
    {
        $secret = $this->google2fa->generateSecretKey();

        $user->forceFill([
            'two_factor_secret' => $secret,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ])->save();

        return $secret;
    }

    /**
     * Complete enrolment if the code matches the pending secret.
     *
     * Serialized on the administrator's own row. Two confirmations arriving
     * together would otherwise both validate the pending secret, both generate
     * a set of recovery codes and both save — last write wins, and whoever lost
     * walks away holding eight codes that will never work. Single-use
     * credentials cannot be decided by write order.
     *
     * The second caller finds enrolment already confirmed and is told so by the
     * same null this returns for a wrong code: there is no way to show a set of
     * plaintext codes again, and inventing one would mean keeping them readable.
     *
     * @return list<string>|null The recovery codes, shown once, or null if the
     *                           code was wrong or enrolment was already confirmed.
     */
    public function confirm(User $user, string $code): ?array
    {
        $recoveryCodes = DB::transaction(function () use ($user, $code): ?array {
            /** @var User|null $locked */
            $locked = User::query()->whereKey($user->getKey())->lockForUpdate()->first();

            if (! $locked instanceof User) {
                return null;
            }

            if ($locked->two_factor_confirmed_at !== null) {
                // Somebody already finished this enrolment. Confirming again
                // would replace a live set of recovery codes with a new one and
                // silently invalidate whatever the first caller was shown.
                return null;
            }

            // Verified against the secret as it is now, inside the lock: a
            // restarted enrolment issues a new secret, and the copy this
            // request arrived with may be the previous one.
            if (! $this->verifyCode($locked, $code)) {
                return null;
            }

            $generated = $this->generateRecoveryCodes();

            $locked->forceFill([
                'two_factor_recovery_codes' => $generated,
                'two_factor_confirmed_at' => now(),
            ])->save();

            return $generated;
        });

        if ($recoveryCodes !== null) {
            // The caller's instance predates the confirmation.
            $user->refresh();
        }

        return $recoveryCodes;
    }

    /**
     * Whether a TOTP code is currently valid for this account.
     */
    public function verifyCode(User $user, string $code): bool
    {
        $secret = $user->two_factor_secret;

        if (! is_string($secret) || $secret === '') {
            return false;
        }

        $code = trim($code);

        if (! preg_match('/^\d{6}$/', $code)) {
            return false;
        }

        return $this->google2fa->verifyKey($secret, $code, self::WINDOW);
    }

    /**
     * Verify a second-factor challenge.
     *
     * Accepts either a current TOTP code or an unused recovery code. The caller
     * is told only whether the attempt succeeded: distinguishing "wrong TOTP"
     * from "wrong recovery code" would tell an attacker which of the two they
     * are closer to guessing.
     */
    public function verifyChallenge(User $user, string $code): bool
    {
        if ($this->verifyCode($user, $code)) {
            return true;
        }

        return $this->consumeRecoveryCode($user, $code);
    }

    /**
     * Spend a recovery code, if it matches an unused one.
     *
     * The row is locked and re-read inside the transaction rather than trusting
     * the copy already in memory. Two requests arriving with the same code at
     * the same time would otherwise both read the code as unused and both
     * succeed, which would make a single-use credential reusable.
     */
    public function consumeRecoveryCode(User $user, string $code): bool
    {
        $candidate = trim($code);

        if ($candidate === '') {
            return false;
        }

        $consumed = DB::transaction(function () use ($user, $candidate): bool {
            /** @var User|null $locked */
            $locked = User::query()->whereKey($user->getKey())->lockForUpdate()->first();

            if (! $locked instanceof User) {
                return false;
            }

            $codes = $locked->two_factor_recovery_codes;

            if (! is_array($codes) || $codes === []) {
                return false;
            }

            $remaining = [];
            $matched = false;

            foreach ($codes as $stored) {
                // Constant-time comparison: recovery codes are credentials, and
                // a length-dependent comparison leaks how much of one is right.
                if (! $matched && is_string($stored) && hash_equals($stored, $candidate)) {
                    $matched = true;

                    continue;
                }

                $remaining[] = $stored;
            }

            if (! $matched) {
                return false;
            }

            $locked->forceFill(['two_factor_recovery_codes' => $remaining])->save();

            return true;
        });

        if ($consumed) {
            // The caller's instance still holds the pre-consumption list.
            $user->refresh();
        }

        return $consumed;
    }

    /**
     * Remove second-factor protection.
     *
     * Used when an administrator loses their device; the caller is responsible
     * for auditing it, because it lowers the account's protection.
     */
    public function disable(User $user): void
    {
        $user->forceFill([
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ])->save();
    }

    /**
     * The otpauth:// URI an authenticator app scans.
     */
    public function provisioningUri(User $user, string $secret): string
    {
        return $this->google2fa->getQRCodeUrl(
            (string) config('app.name'),
            $user->email ?? ('user-'.$user->getKey()),
            $secret,
        );
    }

    /**
     * @return list<string>
     */
    private function generateRecoveryCodes(): array
    {
        $codes = [];

        for ($i = 0; $i < self::RECOVERY_CODE_COUNT; $i++) {
            $codes[] = Str::lower(Str::random(5).'-'.Str::random(5));
        }

        return $codes;
    }
}
