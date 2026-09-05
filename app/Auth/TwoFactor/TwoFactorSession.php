<?php

declare(strict_types=1);

namespace App\Auth\TwoFactor;

use App\Models\User;
use Illuminate\Contracts\Session\Session;

/**
 * Records that the current session has passed a second-factor challenge.
 *
 * The state lives in the session and nowhere else. There is deliberately no
 * database column saying "this account has passed 2FA", because such a flag
 * would outlive the session that earned it and make a stolen password
 * sufficient again on the next login.
 *
 * What is stored is not a secret: the id of the account that passed and when.
 * A TOTP code, a recovery code or the shared secret must never be put here.
 */
final readonly class TwoFactorSession
{
    private const KEY = 'auth.two_factor';

    public function __construct(private Session $session) {}

    /**
     * Record a successful challenge for this session.
     */
    public function markVerified(User $user): void
    {
        // A fresh session id after a privilege step limits the value of any id
        // an attacker may have fixed beforehand.
        $this->session->regenerate();

        $this->session->put(self::KEY, [
            'user_id' => $user->getKey(),
            'at' => now()->timestamp,
        ]);
    }

    /**
     * Whether this session passed a challenge as this specific account.
     *
     * Bound to the user id so that state earned by one account can never carry
     * over to another that happens to reuse the session.
     */
    public function isVerifiedFor(User $user): bool
    {
        $state = $this->session->get(self::KEY);

        if (! is_array($state) || ! isset($state['user_id'])) {
            return false;
        }

        return $state['user_id'] === $user->getKey();
    }

    public function forget(): void
    {
        $this->session->forget(self::KEY);
    }
}
