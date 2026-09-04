<?php

declare(strict_types=1);

namespace App\Wallet;

use App\Audit\AuditEvent;
use App\Audit\AuditRecorder;
use App\Enums\Permission;
use App\Enums\WalletTransactionType;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Support\Secrets\SecretScrubber;
use App\Wallet\Exceptions\IdempotencyConflict;
use App\Wallet\Exceptions\InsufficientBalance;
use App\Wallet\Exceptions\InvalidWalletAmount;
use App\Wallet\Exceptions\UnauthorizedAdjustment;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * The only way customer money moves.
 *
 * Nothing else may write `users.wallet_balance_toman`. Every movement here
 * happens inside one transaction that locks the customer's row first, so two
 * requests arriving at once are serialised by PostgreSQL rather than by hope.
 * The ledger row and the new balance are written together or not at all.
 *
 * Redis is not involved. A distributed lock would coordinate the application
 * with itself while the database remained the thing that could actually be
 * wrong; row locking and unique constraints are what make this correct.
 *
 * All amounts are whole Toman as PHP integers. No method here accepts or
 * returns a float.
 */
final readonly class WalletService
{
    public function __construct(private AuditRecorder $audit) {}

    /**
     * Add money to a wallet.
     *
     * @param  array<string, mixed>  $metadata
     */
    public function credit(
        User $user,
        int $amountToman,
        string $idempotencyKey,
        string $description,
        ?Model $reference = null,
        array $metadata = [],
    ): WalletTransaction {
        if ($amountToman <= 0) {
            throw InvalidWalletAmount::mustBePositive('credit');
        }

        return $this->apply(
            $user, WalletTransactionType::Credit, $amountToman,
            $idempotencyKey, $description, $reference, $metadata, AuditEvent::WalletCredit,
        );
    }

    /**
     * Take money out of a wallet.
     *
     * Refuses rather than overdrawing: if the balance cannot cover it, nothing
     * is written at all.
     *
     * @param  array<string, mixed>  $metadata
     */
    public function debit(
        User $user,
        int $amountToman,
        string $idempotencyKey,
        string $description,
        ?Model $reference = null,
        array $metadata = [],
    ): WalletTransaction {
        if ($amountToman <= 0) {
            throw InvalidWalletAmount::mustBePositive('debit');
        }

        // Stored negative so the ledger sums to the balance without needing to
        // interpret each row's type.
        return $this->apply(
            $user, WalletTransactionType::Debit, -$amountToman,
            $idempotencyKey, $description, $reference, $metadata, AuditEvent::WalletDebit,
        );
    }

    /**
     * Return money to a customer.
     *
     * The primitive only. What makes a refund owed — a failed provisioning, a
     * cancelled order — is decided by the code that calls this.
     *
     * @param  array<string, mixed>  $metadata
     */
    public function refund(
        User $user,
        int $amountToman,
        string $idempotencyKey,
        string $description,
        ?Model $reference = null,
        array $metadata = [],
    ): WalletTransaction {
        if ($amountToman <= 0) {
            throw InvalidWalletAmount::mustBePositive('refund');
        }

        return $this->apply(
            $user, WalletTransactionType::Refund, $amountToman,
            $idempotencyKey, $description, $reference, $metadata, AuditEvent::WalletRefund,
        );
    }

    /**
     * Correct a balance by hand.
     *
     * The only signed operation, and the only one that needs a person to answer
     * for it: an explicit privileged actor and a written reason, both recorded
     * in the audit trail. It still cannot take a balance below zero.
     *
     * @param  array<string, mixed>  $metadata
     */
    public function adjust(
        User $user,
        int $signedAmountToman,
        string $idempotencyKey,
        string $reason,
        User $actor,
        array $metadata = [],
    ): WalletTransaction {
        if (! $actor->isActive() || ! $actor->checkPermissionTo(Permission::WalletAdjust->value)) {
            throw UnauthorizedAdjustment::forActor();
        }

        if (trim($reason) === '') {
            // An adjustment with no stated reason is indistinguishable from an
            // error, and unreviewable afterwards.
            throw UnauthorizedAdjustment::missingReason();
        }

        if ($signedAmountToman === 0) {
            throw InvalidWalletAmount::zero();
        }

        return $this->apply(
            $user, WalletTransactionType::Adjustment, $signedAmountToman,
            $idempotencyKey, trim($reason), null, $metadata, AuditEvent::WalletAdjusted, $actor,
        );
    }

    /**
     * The one place the balance changes.
     *
     * @param  array<array-key, mixed>  $metadata
     */
    private function apply(
        User $user,
        WalletTransactionType $type,
        int $signedAmountToman,
        string $idempotencyKey,
        string $description,
        ?Model $reference,
        array $metadata,
        string $auditEvent,
        ?User $actor = null,
    ): WalletTransaction {
        // Before anything is written, and once, so the ledger row, the audit
        // entry and any later reader all see the same text. The ledger is
        // immutable and kept for years: a credential written here could never
        // be taken back out, and a scrubbed row is still perfectly legible for
        // the forensic purpose the ledger actually serves.
        $description = SecretScrubber::scrubText($description);
        $metadata = SecretScrubber::scrub($metadata);

        return DB::transaction(function () use (
            $user, $type, $signedAmountToman, $idempotencyKey,
            $description, $reference, $metadata, $auditEvent, $actor
        ): WalletTransaction {
            // Locked before anything is read or decided. Everything below runs
            // with this customer's wallet held, so a concurrent movement waits
            // here rather than computing a balance that is about to be stale.
            $locked = User::query()->whereKey($user->getKey())->lockForUpdate()->first();

            if (! $locked instanceof User) {
                // The customer vanished between the caller loading them and
                // this lock. Refusing is the only safe answer: there is no
                // wallet to move money into.
                throw new ModelNotFoundException('The wallet owner no longer exists.');
            }

            $existing = WalletTransaction::query()
                ->where('idempotency_key', $idempotencyKey)
                ->first();

            if ($existing instanceof WalletTransaction) {
                // A genuine replay returns what already happened. A key reused
                // for something else is refused rather than answered with a
                // result that belongs to a different operation.
                $this->assertSameOperation($existing, $locked, $type, $signedAmountToman, $reference);

                return $existing;
            }

            $before = $locked->wallet_balance_toman;
            $after = $before + $signedAmountToman;

            if ($after < 0) {
                throw $type === WalletTransactionType::Adjustment
                    ? InsufficientBalance::forAdjustment($before, $signedAmountToman)
                    : InsufficientBalance::forDebit($before, abs($signedAmountToman));
            }

            try {
                $transaction = WalletTransaction::query()->create([
                    'user_id' => $locked->getKey(),
                    'type' => $type,
                    'amount_toman' => $signedAmountToman,
                    'balance_before_toman' => $before,
                    'balance_after_toman' => $after,
                    'idempotency_key' => $idempotencyKey,
                    'reference_type' => $reference?->getMorphClass(),
                    'reference_id' => $reference === null ? null : (string) $reference->getKey(),
                    'description' => $description,
                    'metadata' => $metadata === [] ? null : $metadata,
                ]);
            } catch (QueryException $exception) {
                // The row lock serialises movements for one customer, so this
                // can only be the same key arriving for a different customer —
                // which is precisely the conflict that must fail closed.
                throw IdempotencyConflict::on($idempotencyKey, 'user');
            }

            $locked->forceFill(['wallet_balance_toman' => $after])->save();

            // Inside the transaction: a movement that committed without its
            // audit entry would be money that moved with no record of why.
            $this->audit->record(
                $auditEvent,
                actor: $actor,
                subject: $locked,
                before: ['balance_toman' => $before],
                after: ['balance_toman' => $after],
                metadata: [
                    'wallet_transaction_id' => $transaction->getKey(),
                    'type' => $type->value,
                    'amount_toman' => $signedAmountToman,
                    'description' => $description,
                    'reference_type' => $reference?->getMorphClass(),
                    'reference_id' => $reference === null ? null : (string) $reference->getKey(),
                ],
            );

            return $transaction;
        });
    }

    /**
     * Confirm a replayed key describes the same operation.
     *
     * Every field that changes what the money did is compared. Anything else
     * would let one key stand for two different movements.
     */
    private function assertSameOperation(
        WalletTransaction $existing,
        User $user,
        WalletTransactionType $type,
        int $signedAmountToman,
        ?Model $reference,
    ): void {
        if ($existing->user_id !== $user->getKey()) {
            throw IdempotencyConflict::on($existing->idempotency_key, 'user');
        }

        if ($existing->type !== $type) {
            throw IdempotencyConflict::on($existing->idempotency_key, 'type');
        }

        if ($existing->amount_toman !== $signedAmountToman) {
            throw IdempotencyConflict::on($existing->idempotency_key, 'amount');
        }

        $referenceType = $reference?->getMorphClass();
        $referenceId = $reference === null ? null : (string) $reference->getKey();

        if ($existing->reference_type !== $referenceType || $existing->reference_id !== $referenceId) {
            throw IdempotencyConflict::on($existing->idempotency_key, 'reference');
        }
    }
}
