<?php

declare(strict_types=1);

namespace App\Provisioning;

use App\Cloud\Capabilities\SupportsPasswordReset;
use App\Cloud\Contracts\CloudProviderInterface;
use App\Cloud\Data\ProviderPasswordResetData;
use App\Cloud\Data\SensitiveRootCredential;
use App\Cloud\Enums\ProviderActionStatus;
use App\Cloud\Enums\ProviderErrorCategory;
use App\Cloud\Exceptions\ProviderException;
use App\Enums\ProvisioningOutcome;
use App\Enums\ProvisioningStage;
use App\Models\Order;
use App\Models\ProvisioningAttempt;
use App\Provisioning\Data\ProvisioningPlan;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Support\Facades\Log;

/**
 * Gets a usable root password for a machine that exists but was never delivered.
 *
 * The situation this exists for is narrow and unavoidable. A provider create
 * succeeds and its response carries the only copy of a generated root password.
 * The local write then fails, or the worker dies, and that password is gone from
 * memory. Later reconciliation finds the machine by its durable provisioning
 * token — correctly, and credential-free, because the ordinary provider reads
 * must never reveal a one-time secret.
 *
 * The lost password is not recoverable and no attempt is made to recover it. It
 * is not guessed, not stored anywhere in advance, not put in metadata, and no
 * second machine is created to get a fresh one. Instead the provider is asked to
 * issue a new password for the machine that already exists.
 *
 * Rotating is safe here in a way that repeating a create, a reboot or a delete
 * never is, and the reason is specific rather than general: the customer has not
 * been given this server yet. No password has been shown to anybody, so
 * invalidating the old one locks nobody out; no VPS is duplicated; no data is
 * destroyed. The moment a server has been delivered that stops being true, which
 * is why this is an internal provisioning path and not a customer feature.
 *
 * The budget is its own. `orders.attempts` is the provider *create* budget and
 * is deliberately untouched here — spending a create attempt on a credential
 * rotation would let a run of reset failures retire an order that has a perfectly
 * good machine waiting. Credential recovery is counted on its own attempt stage
 * instead, durably, in PostgreSQL.
 */
final readonly class CredentialRecovery
{
    public function __construct(
        private AttemptRecorder $attempts,
        private Config $config,
    ) {}

    /** The provider cannot issue a replacement, so nobody can log in. */
    public const Unsupported = 'credential_recovery_unsupported';

    /** The provider can, and did not, within the durable bound. */
    public const Exhausted = 'credential_recovery_exhausted';

    /**
     * A recovery failed but the durable budget still has room.
     *
     * Kept distinct from exhaustion because the two owe a customer different
     * things. Budget remaining means the next sweep tries again and nobody has
     * to be told; budget spent means a person has to look at a machine that
     * exists and cannot be handed over.
     */
    public const Retryable = 'credential_recovery_retryable';

    /**
     * Obtain a fresh credential for a machine that exists remotely.
     *
     * Returns the credential on success, or one of the reasons above. Never
     * throws for an ordinary provider failure: the caller has a customer with a
     * billable machine and needs a decision, not an exception.
     *
     * The caller must already hold this order's provisioning lock, and must not
     * hold a database transaction — there are provider calls in here.
     */
    public function recover(
        Order $order,
        ProvisioningPlan $plan,
        CloudProviderInterface $provider,
        string $providerServerId,
    ): SensitiveRootCredential|string {
        if (! $provider instanceof SupportsPasswordReset) {
            // A real machine the customer paid for, and no safe way to obtain
            // access to it. Not a refund — the machine exists and is billable —
            // and emphatically not a delivery, because delivering a server
            // nobody can log into is worse than admitting the problem.
            return self::Unsupported;
        }

        if ($this->used($order) >= $this->maximum()) {
            return self::Exhausted;
        }

        $attempt = $this->attempts->open($order, ProvisioningStage::CredentialRecovery, $plan);

        try {
            $reset = $provider->resetRootPassword($providerServerId);
        } catch (ProviderException $exception) {
            $this->attempts->close(
                $attempt,
                ProvisioningStage::CredentialRecovery,
                ProvisioningOutcome::TransientFailure,
                $exception->category,
                extra: ['provider_server_id' => $providerServerId],
            );

            return $this->isExhausted($order) ? self::Exhausted : self::Retryable;
        }

        $settled = $this->settle($provider, $reset);

        if (! $settled instanceof ProviderPasswordResetData || ! $settled->isUsable()) {
            // Accepted but never confirmed, or confirmed without a password.
            // The attempt is spent and the machine is untouched; a later
            // recovery may rotate again, and the newer password supersedes any
            // earlier one nobody ever received.
            $this->attempts->close(
                $attempt,
                ProvisioningStage::CredentialRecovery,
                ProvisioningOutcome::Uncertain,
                ProviderErrorCategory::UncertainResult,
                extra: ['provider_server_id' => $providerServerId],
            );

            return $this->isExhausted($order) ? self::Exhausted : self::Retryable;
        }

        $this->attempts->close(
            $attempt,
            ProvisioningStage::CredentialRecovery,
            ProvisioningOutcome::Succeeded,
            extra: ['provider_server_id' => $providerServerId],
        );

        // Identifiers and a stage. Never the password, and never the provider's
        // own text about it.
        Log::info('provisioning.credential_recovered', [
            'order_id' => $order->getKey(),
            'provider_server_id' => $providerServerId,
            'attempt' => $this->used($order),
        ]);

        return $settled->rootCredential;
    }

    /**
     * Whether this order has spent its credential-recovery budget.
     */
    public function isExhausted(Order $order): bool
    {
        return $this->used($order) >= $this->maximum();
    }

    /**
     * How many credential recoveries this order has already attempted.
     *
     * Counted from the forensic attempt table by stage, so it survives a worker
     * restart, a redispatch and an operator running the sweep by hand — none of
     * which a queue's own try counter survives.
     */
    public function used(Order $order): int
    {
        return ProvisioningAttempt::query()
            ->where('order_id', $order->getKey())
            ->where('stage', ProvisioningStage::CredentialRecovery->value)
            ->count();
    }

    /** The operational bound. Not the VPS create budget, and never conflated with it. */
    public function maximum(): int
    {
        return max(1, (int) $this->config->get('cloudbot.provisioning.credential_recovery_max_attempts', 3));
    }

    /**
     * Wait out an asynchronous reset, within one bounded execution window.
     *
     * A provider may accept a reset and finish it a moment later. Polling here
     * rather than returning keeps the credential in the one frame that is
     * allowed to hold it — parking it durably to poll later is exactly the
     * secret store this design refuses to have.
     *
     * The window is bounded and short. If it closes, the password is lost, and
     * that is acceptable precisely because the server has not been delivered:
     * the next recovery rotates again.
     */
    private function settle(
        CloudProviderInterface $provider,
        ProviderPasswordResetData $reset,
    ): ?ProviderPasswordResetData {
        if ($reset->status !== ProviderActionStatus::Running) {
            return $reset;
        }

        $deadline = microtime(true) + $this->pollSeconds();

        while (microtime(true) < $deadline) {
            usleep(200_000);

            try {
                $action = $provider->getAction($reset->providerActionId);
            } catch (ProviderException) {
                // Cannot read it. Says nothing about the reset, and nothing is
                // claimed from not knowing.
                return null;
            }

            if ($action->status === ProviderActionStatus::Success) {
                // The password came back with the original acceptance; the poll
                // was only ever confirming that the machine now accepts it.
                return $reset;
            }

            if ($action->status === ProviderActionStatus::Error) {
                return null;
            }
        }

        return null;
    }

    private function pollSeconds(): int
    {
        return max(1, (int) $this->config->get('cloudbot.provisioning.credential_recovery_poll_seconds', 10));
    }
}
