<?php

namespace App\Exceptions;

use RuntimeException;

class InsufficientWalletBalanceException extends RuntimeException
{
    public static function forWallet(int $walletId, int $balanceToman, int $amountToman): self
    {
        return new self(
            "Wallet [{$walletId}] has insufficient balance: {$balanceToman} toman available, {$amountToman} required."
        );
    }

    public static function forMinimumPrepaid(int $userId, int $balanceToman, int $requiredToman, int $minimumHours): self
    {
        return new self(
            "User [{$userId}] wallet balance is below the minimum prepaid requirement: {$balanceToman} toman available, {$requiredToman} required ({$minimumHours} hours)."
        );
    }
}
