<?php

namespace App\Services;

use App\Exceptions\InsufficientWalletBalanceException;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * The single authority over wallet balances.
 *
 * No other code may mutate wallet.balance_toman directly. Every mutation
 * runs inside a transaction with a row lock and produces an auditable
 * WalletTransaction row carrying the post-mutation balance.
 */
class WalletService
{
    public function walletFor(User $user): Wallet
    {
        try {
            $wallet = Wallet::query()->firstOrCreate(
                ['user_id' => $user->id],
                ['balance_toman' => 0],
            );
        } catch (UniqueConstraintViolationException) {
            // Concurrent creation race: the row exists, reuse it.
            $wallet = Wallet::query()->where('user_id', $user->id)->firstOrFail();
        }

        // The relation may have been lazily cached as null; keep it in sync.
        $user->setRelation('wallet', $wallet);

        return $wallet;
    }

    public function credit(
        User|Wallet $walletOrUser,
        int $amountToman,
        ?string $description = null,
        ?Model $reference = null,
    ): WalletTransaction {
        if ($amountToman <= 0) {
            throw new InvalidArgumentException('Credit amount must be a positive integer of toman.');
        }

        return DB::transaction(function () use ($walletOrUser, $amountToman, $description, $reference) {
            $wallet = $this->resolveLocked($walletOrUser);

            $balanceAfter = $wallet->balance_toman + $amountToman;
            $wallet->update(['balance_toman' => $balanceAfter]);

            return $this->record(
                $wallet,
                WalletTransaction::TYPE_CREDIT,
                $amountToman,
                $balanceAfter,
                $description,
                $reference,
            );
        });
    }

    public function debit(
        User|Wallet $walletOrUser,
        int $amountToman,
        ?string $description = null,
        ?Model $reference = null,
    ): WalletTransaction {
        if ($amountToman <= 0) {
            throw new InvalidArgumentException('Debit amount must be a positive integer of toman.');
        }

        return DB::transaction(function () use ($walletOrUser, $amountToman, $description, $reference) {
            $wallet = $this->resolveLocked($walletOrUser);

            if ($wallet->balance_toman < $amountToman) {
                throw InsufficientWalletBalanceException::forWallet(
                    $wallet->id,
                    $wallet->balance_toman,
                    $amountToman,
                );
            }

            $balanceAfter = $wallet->balance_toman - $amountToman;
            $wallet->update(['balance_toman' => $balanceAfter]);

            return $this->record(
                $wallet,
                WalletTransaction::TYPE_DEBIT,
                $amountToman,
                $balanceAfter,
                $description,
                $reference,
            );
        });
    }

    private function resolveLocked(User|Wallet $walletOrUser): Wallet
    {
        if ($walletOrUser instanceof User) {
            $wallet = $this->walletFor($walletOrUser);

            /** @var Wallet $locked */
            $locked = Wallet::query()->lockForUpdate()->findOrFail($wallet->id);

            // Keep the user's cached relation in sync with the locked instance
            // that the mutation will update — otherwise callers reading
            // $user->wallet after a credit/debit observe a stale balance.
            $walletOrUser->setRelation('wallet', $locked);

            return $locked;
        }

        /** @var Wallet $locked */
        $locked = Wallet::query()->lockForUpdate()->findOrFail($walletOrUser->id);

        return $locked;
    }

    private function record(
        Wallet $wallet,
        string $type,
        int $amountToman,
        int $balanceAfter,
        ?string $description,
        ?Model $reference,
    ): WalletTransaction {
        return WalletTransaction::query()->create([
            'wallet_id' => $wallet->id,
            'type' => $type,
            'amount_toman' => $amountToman,
            'balance_after' => $balanceAfter,
            'reference_type' => $reference !== null ? $reference->getMorphClass() : null,
            'reference_id' => $reference?->getKey(),
            'description' => $description,
        ]);
    }
}
