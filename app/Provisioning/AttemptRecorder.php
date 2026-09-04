<?php

declare(strict_types=1);

namespace App\Provisioning;

use App\Audit\AuditEvent;
use App\Audit\AuditRecorder;
use App\Cloud\Data\ProviderServerData;
use App\Cloud\Enums\ProviderErrorCategory;
use App\Enums\ProvisioningOutcome;
use App\Enums\ProvisioningStage;
use App\Models\Order;
use App\Models\ProvisioningAttempt;
use App\Provisioning\Data\ProvisioningPlan;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Opens and closes the forensic record of each provider call.
 *
 * An attempt is written *before* the call and committed, so that a worker which
 * dies mid-call still leaves evidence that a call was made. That ordering is the
 * point: the dangerous state is a remote server nobody knows was asked for, and
 * a record written only on the way back would never exist in exactly the case
 * that matters most.
 *
 * Attempt numbers come from the database, not from counting in PHP. Two workers
 * that both read "two attempts so far" would both write attempt three; the
 * unique index refuses the second, and the loser takes the next number instead.
 *
 * Nothing here rewrites an earlier row. A reconciliation that later finds the
 * server records its own attempt; the one that timed out still says so.
 */
final readonly class AttemptRecorder
{
    public function __construct(private AuditRecorder $audit) {}

    /**
     * Record that a call is about to be made, and commit it.
     *
     * Runs in its own transaction on purpose. The caller must not be holding one:
     * an attempt row that rolls back with the work it was recording is not
     * evidence of anything.
     */
    public function open(Order $order, ProvisioningStage $stage, ProvisioningPlan $plan): ProvisioningAttempt
    {
        $summary = self::requestSummary($order, $plan);

        // Retried rather than pre-counted. The unique index on
        // (order_id, attempt_no) is the arbiter, and losing to it simply means
        // somebody else took this number.
        for ($tries = 0; $tries < 5; $tries++) {
            $next = $this->nextAttemptNumber($order);

            try {
                $attempt = DB::transaction(fn (): ProvisioningAttempt => ProvisioningAttempt::query()->create([
                    'order_id' => $order->getKey(),
                    'provisioning_uuid' => (string) $order->provisioning_uuid,
                    'attempt_no' => $next,
                    'stage' => $stage->value,
                    'outcome' => ProvisioningOutcome::InFlight->value,
                    'started_at' => CarbonImmutable::now(),
                    'request_summary' => $summary,
                ]));
            } catch (QueryException) {
                continue;
            }

            $this->audit->record(
                AuditEvent::ProvisioningAttemptStarted,
                subject: $order,
                metadata: [
                    'order_id' => $order->getKey(),
                    'attempt_no' => $attempt->attempt_no,
                    'stage' => $stage->value,
                    'provider_code' => $plan->providerCode,
                ],
            );

            return $attempt;
        }

        throw new ModelNotFoundException('Could not claim a provisioning attempt number.');
    }

    /**
     * Close an attempt with what actually happened.
     *
     * @param  array<string, scalar|null>  $extra  Additional safe facts.
     */
    public function close(
        ProvisioningAttempt $attempt,
        ProvisioningStage $stage,
        ProvisioningOutcome $outcome,
        ?ProviderErrorCategory $category = null,
        ?ProviderServerData $server = null,
        array $extra = [],
    ): ProvisioningAttempt {
        $attempt->forceFill([
            'stage' => $stage,
            'outcome' => $outcome,
            'error_category' => $category,
            'finished_at' => CarbonImmutable::now(),
            'result_summary' => self::resultSummary($server, $extra),
        ])->save();

        if ($outcome !== ProvisioningOutcome::Succeeded && $outcome !== ProvisioningOutcome::RecoveredExisting) {
            $this->audit->record(
                AuditEvent::ProvisioningAttemptFailed,
                subject: $attempt->order,
                metadata: [
                    'order_id' => $attempt->order_id,
                    'attempt_no' => $attempt->attempt_no,
                    'stage' => $stage->value,
                    'outcome' => $outcome->value,
                    // The normalized category, never the provider's prose.
                    'error_category' => $category?->value,
                ],
            );
        }

        return $attempt;
    }

    /** How many calls this order has already made. */
    public function attemptCount(Order $order): int
    {
        return ProvisioningAttempt::query()->where('order_id', $order->getKey())->count();
    }

    /**
     * What we asked for. Whitelisted facts, assembled here rather than dumped.
     *
     * No credential can reach this: every value is a catalog identifier or a
     * number the order already holds, and nothing is copied out of a provider
     * response or an exception.
     *
     * @return array<string, mixed>
     */
    public static function requestSummary(Order $order, ProvisioningPlan $plan): array
    {
        return [
            'provider_code' => $plan->providerCode,
            'provider_plan_code' => $plan->providerPlanCode,
            'provider_location_code' => $plan->providerLocationCode,
            'provider_image_code' => $plan->providerImageCode,
            'server_name' => OrderPlanner::serverName($order),
            'order_number' => $order->order_number,
        ];
    }

    /**
     * What came back. The remote identity and its state, and nothing else.
     *
     * Explicitly not the ProviderServerData object, not its metadata and not
     * the response it was built from: a whitelist that is written out by hand
     * is the only kind that stays a whitelist.
     *
     * @param  array<string, scalar|null>  $extra
     * @return array<string, mixed>|null
     */
    public static function resultSummary(?ProviderServerData $server, array $extra = []): ?array
    {
        if ($server === null) {
            return $extra === [] ? null : $extra;
        }

        return [
            'provider_server_id' => $server->providerServerId,
            'provider_status' => $server->status->value,
            'provider_power_state' => $server->powerState->value,
            'provider_plan_code' => $server->providerPlanId,
            'provider_location_code' => $server->providerLocationId,
            'provider_image_code' => $server->providerImageId,
            // Whether the token came back matching, not the token twice over.
            'token_matched' => $server->provisioningToken !== null,
            ...$extra,
        ];
    }

    private function nextAttemptNumber(Order $order): int
    {
        $highest = ProvisioningAttempt::query()
            ->where('order_id', $order->getKey())
            ->max('attempt_no');

        return is_numeric($highest) ? ((int) $highest) + 1 : 1;
    }
}
