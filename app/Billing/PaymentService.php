<?php

declare(strict_types=1);

namespace App\Billing;

use App\Audit\AuditEvent;
use App\Audit\AuditRecorder;
use App\Billing\Contracts\PaymentGatewayInterface;
use App\Billing\Exceptions\GatewayMismatch;
use App\Billing\Exceptions\PaymentIdempotencyConflict;
use App\Billing\Exceptions\PaymentNotVerifiable;
use App\Billing\Exceptions\UnauthorizedVerification;
use App\Enums\PaymentStatus;
use App\Enums\Permission;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\User;
use App\Support\Secrets\SecretScrubber;
use App\Wallet\WalletService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Creates payments and settles them.
 *
 * Settlement is where a claim becomes money, so it happens once and only once.
 * The customer's row is locked before the payment's, matching the order the
 * wallet uses, so a settlement and a concurrent wallet movement queue behind
 * the same lock instead of deadlocking against each other.
 *
 * No network call happens anywhere in here, and none may be added: an external
 * request while a customer's wallet row is held would hold that lock for as
 * long as someone else's server takes to answer.
 */
final readonly class PaymentService
{
    public function __construct(
        private WalletService $wallet,
        private InvoiceService $invoices,
        private AuditRecorder $audit,
    ) {}

    /**
     * Record an intention to pay. Moves no money.
     *
     * @param  array<string, scalar|null>  $metadata
     */
    public function createPayment(
        User $user,
        PaymentGatewayInterface $gateway,
        int $amountToman,
        string $idempotencyKey,
        ?string $receiptPath = null,
        array $metadata = [],
    ): Payment {
        if ($amountToman <= 0) {
            throw new \InvalidArgumentException('A payment must be for a positive amount.');
        }

        $existing = Payment::query()->where('idempotency_key', $idempotencyKey)->first();

        if ($existing instanceof Payment) {
            $this->assertSameIntention($existing, $user, $gateway, $amountToman);

            return $existing;
        }

        try {
            return Payment::query()->create([
                'user_id' => $user->getKey(),
                'gateway' => $gateway->code(),
                'amount_toman' => $amountToman,
                'status' => PaymentStatus::Pending,
                'idempotency_key' => $idempotencyKey,
                'receipt_path' => $receiptPath,
                'request_metadata' => SecretScrubber::scrub($metadata) ?: null,
            ]);
        } catch (QueryException $exception) {
            // Two concurrent requests carrying one key. Whichever landed first
            // is the payment; the other must not become a second one.
            $winner = Payment::query()->where('idempotency_key', $idempotencyKey)->first();

            if ($winner instanceof Payment) {
                $this->assertSameIntention($winner, $user, $gateway, $amountToman);

                return $winner;
            }

            throw $exception;
        }
    }

    /**
     * Accept a payment and credit the wallet.
     *
     * Everything that must be true together happens in one transaction: the
     * payment is marked paid, the wallet is credited, and the invoice is
     * issued. A crash between any two of them would otherwise leave a customer
     * charged without funds, or funded without a record.
     *
     * @param  array<string, scalar|null>  $evidence
     * @return array{payment: Payment, invoice: Invoice}
     */
    public function verify(
        Payment $payment,
        PaymentGatewayInterface $gateway,
        User $verifier,
        array $evidence,
    ): array {
        if (! $verifier->isActive() || ! $verifier->checkPermissionTo(Permission::PaymentsManage->value)) {
            // Checked before anything is read or locked. Support staff handle
            // customers and servers; accepting money is a finance decision.
            throw UnauthorizedVerification::forActor();
        }

        // Before the gateway is consulted at all. A future automated gateway
        // verifies by calling out to a remote API, and a payment it does not
        // own must not cause that request to be made — let alone have its
        // answer applied.
        $this->assertGatewayOwns($payment, $gateway);

        $result = $gateway->verify($payment, $evidence);

        if (! $result->verified || $result->reference === null) {
            throw PaymentNotVerifiable::rejected($result->message);
        }

        return DB::transaction(function () use ($payment, $gateway, $verifier, $result): array {
            // User first, then payment. The wallet locks the user too, so this
            // order is the one that keeps the two flows from deadlocking.
            $customer = User::query()->whereKey($payment->user_id)->lockForUpdate()->first();
            $locked = Payment::query()->whereKey($payment->getKey())->lockForUpdate()->first();

            if (! $customer instanceof User || ! $locked instanceof Payment) {
                throw new ModelNotFoundException('The payment or its owner no longer exists.');
            }

            // Again, on the row the database actually holds. The instance the
            // caller passed in was read earlier and may no longer describe it;
            // this is the copy the settlement is about to act on, so this is
            // the copy that has to match.
            $this->assertGatewayOwns($locked, $gateway);

            if ($locked->status->isSettled()) {
                // Already accepted, by an earlier call or a concurrent one.
                // Return what exists rather than crediting the wallet again.
                return [
                    'payment' => $locked,
                    'invoice' => $this->invoices->issueForPayment($locked),
                ];
            }

            if (! $locked->status->isOpen()) {
                throw PaymentNotVerifiable::notOpen($locked->status->value);
            }

            $this->claimReference($locked, $gateway, $result->reference);

            $locked->forceFill([
                'status' => PaymentStatus::Paid,
                'paid_at' => now(),
                'verified_by_admin_id' => $verifier->getKey(),
                'verification_metadata' => $result->metadata ?: null,
            ])->save();

            // Keyed on the payment, so a replay resolves to this same ledger
            // entry rather than crediting the customer a second time.
            $this->wallet->credit(
                $customer,
                $locked->amount_toman,
                $locked->settlementIdempotencyKey(),
                'Wallet top-up',
                $locked,
            );

            $invoice = $this->invoices->issueForPayment($locked);

            $this->audit->record(
                AuditEvent::PaymentVerified,
                actor: $verifier,
                subject: $locked,
                after: ['status' => PaymentStatus::Paid->value],
                metadata: [
                    'payment_id' => $locked->getKey(),
                    'user_id' => $locked->user_id,
                    'amount_toman' => $locked->amount_toman,
                    'gateway' => $locked->gateway,
                    // The bank reference is an operational fact, not a secret.
                    'reference' => $locked->provider_payment_id,
                    'invoice_number' => $invoice->number,
                ],
            );

            return ['payment' => $locked, 'invoice' => $invoice];
        });
    }

    /**
     * Reject a payment. Credits nothing.
     */
    public function reject(Payment $payment, User $verifier, string $reason): Payment
    {
        if (! $verifier->isActive() || ! $verifier->checkPermissionTo(Permission::PaymentsManage->value)) {
            throw UnauthorizedVerification::forActor();
        }

        return DB::transaction(function () use ($payment, $verifier, $reason): Payment {
            $locked = Payment::query()->whereKey($payment->getKey())->lockForUpdate()->first();

            if (! $locked instanceof Payment) {
                throw new ModelNotFoundException('That payment no longer exists.');
            }

            if (! $locked->status->isOpen()) {
                throw PaymentNotVerifiable::notOpen($locked->status->value);
            }

            $locked->forceFill([
                'status' => PaymentStatus::Failed,
                'verified_by_admin_id' => $verifier->getKey(),
                'verification_metadata' => ['rejected_reason' => SecretScrubber::scrubText($reason)],
            ])->save();

            return $locked;
        });
    }

    /**
     * Refuse to let one gateway act on another's payment.
     *
     * Central rather than left to each implementation: a gateway added later
     * inherits this protection without having to remember it, and a gateway
     * that forgot would be exactly the one that could be abused.
     */
    private function assertGatewayOwns(Payment $payment, PaymentGatewayInterface $gateway): void
    {
        if ($payment->gateway !== $gateway->code()) {
            throw GatewayMismatch::between($payment->gateway, $gateway->code());
        }
    }

    /**
     * Attach the gateway's reference to this payment.
     *
     * The unique index on (gateway, reference) is what actually stops one real
     * transfer settling two payments. An application check alone would let two
     * concurrent verifications past, both having looked before either wrote.
     */
    private function claimReference(Payment $payment, PaymentGatewayInterface $gateway, string $reference): void
    {
        try {
            DB::transaction(function () use ($payment, $reference): void {
                $payment->forceFill(['provider_payment_id' => $reference])->save();
            });
        } catch (QueryException $exception) {
            // Wrapped in a savepoint above so this rejection does not abort the
            // settlement transaction we are inside.
            throw PaymentNotVerifiable::referenceAlreadyUsed();
        }
    }

    private function assertSameIntention(
        Payment $existing,
        User $user,
        PaymentGatewayInterface $gateway,
        int $amountToman,
    ): void {
        if ($existing->user_id !== $user->getKey()) {
            throw PaymentIdempotencyConflict::on($existing->idempotency_key, 'user');
        }

        if ($existing->amount_toman !== $amountToman) {
            throw PaymentIdempotencyConflict::on($existing->idempotency_key, 'amount');
        }

        if ($existing->gateway !== $gateway->code()) {
            throw PaymentIdempotencyConflict::on($existing->idempotency_key, 'gateway');
        }
    }
}
