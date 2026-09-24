<?php

declare(strict_types=1);

namespace App\Provisioning;

use App\Audit\AuditEvent;
use App\Audit\AuditRecorder;
use App\Cloud\Data\ProviderCreateResult;
use App\Cloud\Data\ProviderServerData;
use App\Cloud\Enums\ProviderErrorCategory;
use App\Enums\CredentialEvidence;
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
     * Record that this attempt has reached the create call, and commit.
     *
     * Called after availability succeeds and the create budget is reserved,
     * immediately before the provider is asked to build anything. Without it a
     * worker that dies inside createServer() leaves a row still claiming the
     * stage was `before_create` — forensic history saying no create was reached
     * when one may have been, which is the most misleading thing this table
     * could tell an operator investigating a possible orphan.
     *
     * The outcome stays in flight: what happened is still unknown, and that is
     * the honest value until the call returns.
     */
    public function enterCreateStage(ProvisioningAttempt $attempt): ProvisioningAttempt
    {
        // Committed on its own, outside any transaction the caller holds, so
        // the record is durable before the network call begins.
        $attempt->forceFill([
            'stage' => ProvisioningStage::Create,
            'outcome' => ProvisioningOutcome::InFlight,
        ])->save();

        return $attempt;
    }

    /**
     * Record what the create response said, before anything can lose it.
     *
     * Committed on its own, immediately, and deliberately before local
     * persistence begins. The window this closes is the one Phase 7 is built
     * around: the provider has acted, the response is in memory, and the next
     * thing that happens might be the process ending. Whatever is not durable
     * at that instant is gone.
     *
     * What is written is a fact about the *shape* of the answer, never its
     * content: whether this call built the machine, and whether it issued a
     * root password. No plaintext, no digest of the customer's credential, no
     * provider metadata. A boolean is enough for the only question recovery
     * needs to ask later — does this machine need a password before it can be
     * delivered?
     *
     * Nothing is written for a replay. An `Existing` answer carries no
     * credential because the create already happened, not because the machine
     * has none, and recording `false` there would erase the truth about a
     * password the original create did issue. Silence is the honest value: it
     * leaves the evidence exactly as whatever the real create left it.
     *
     * The stage and outcome are untouched. The provider answering is not the
     * same as the order being delivered, and a create attempt that still has
     * local adoption outstanding must not read as finished.
     */
    public function recordCreateResponse(ProvisioningAttempt $attempt, ProviderCreateResult $created): ProvisioningAttempt
    {
        if (! $created->isNew()) {
            // A replay establishes nothing. Anything already recorded stands.
            return $attempt;
        }

        $attempt->forceFill([
            'result_summary' => self::resultSummary($created->server, [
                'create_disposition' => $created->disposition->value,
                'root_credential_issued' => $created->hasCredential(),
            ]),
        ])->save();

        return $attempt;
    }

    /**
     * What is durably known about this order's root credential.
     *
     * Read from the forensic attempts rather than from anything a provider says
     * now: `ProviderServerData` is credential-free by construction, so asking
     * the provider what kind of machine this is would be reading an expectation
     * into a silence that means nothing.
     *
     * The most recent create response wins, because an order can legitimately
     * create more than once — an earlier attempt whose machine was tombstoned,
     * for instance — and it is the machine that exists now whose credential is
     * in question.
     */
    public function credentialEvidence(Order $order): CredentialEvidence
    {
        $attempts = ProvisioningAttempt::query()
            ->where('order_id', $order->getKey())
            ->orderByDesc('attempt_no')
            ->get(['result_summary']);

        foreach ($attempts as $attempt) {
            $summary = $attempt->result_summary;

            if (! is_array($summary) || ! array_key_exists('root_credential_issued', $summary)) {
                continue;
            }

            return $summary['root_credential_issued'] === true
                ? CredentialEvidence::KnownIssued
                : CredentialEvidence::KnownNone;
        }

        return CredentialEvidence::Unknown;
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
            // Carried forward rather than replaced. What the create response
            // said about credential issuance was committed the moment it
            // arrived, precisely because it might be the only record of it —
            // and closing the attempt with what happened next must not erase
            // it. Rewriting the summary wholesale would delete the one fact
            // recovery reads later.
            'result_summary' => self::resultSummary($server, [
                ...self::carriedFacts($attempt),
                ...$extra,
            ]),
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

    /**
     * The create-response facts already on this attempt, if any.
     *
     * Exactly two keys, both safe and both non-secret. Anything else in an
     * earlier summary is a description of a provider answer that has since been
     * superseded, and carrying it forward would muddle the record.
     *
     * @return array<string, scalar|null>
     */
    private static function carriedFacts(ProvisioningAttempt $attempt): array
    {
        $summary = $attempt->result_summary;

        if (! is_array($summary)) {
            return [];
        }

        $carried = [];

        foreach (['create_disposition', 'root_credential_issued'] as $key) {
            if (array_key_exists($key, $summary) && (is_scalar($summary[$key]) || $summary[$key] === null)) {
                $carried[$key] = $summary[$key];
            }
        }

        return $carried;
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
