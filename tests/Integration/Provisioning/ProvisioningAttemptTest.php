<?php

declare(strict_types=1);

use App\Authorization\RoleProvisioner;
use App\Cloud\Enums\ProviderErrorCategory;
use App\Enums\ProvisioningOutcome;
use App\Enums\ProvisioningStage;
use App\Models\ProvisioningAttempt;
use App\Provisioning\ProvisioningService;
use App\Provisioning\ReconciliationService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Support\Provisioning\ProvisioningFloor;
use Tests\Support\Provisioning\Simulator;

/**
 * The forensic record of what was asked of a provider, and what came back.
 *
 * History rather than working state. An attempt that timed out really did time
 * out, and a reconciliation that later finds the machine records its own row
 * instead of editing that one — because "what did we know at the time" is the
 * question this table exists to answer, and rewriting it destroys the answer.
 */
beforeEach(function (): void {
    app(RoleProvisioner::class)->sync();

    $this->floor = ProvisioningFloor::open();
    $this->provisioning = app(ProvisioningService::class);
});

it('numbers attempts from one, upwards, per order', function (): void {
    $order = $this->floor->paidOrder();

    $scripted = Simulator::script();
    $scripted->rejectCreate(ProviderErrorCategory::TransientProviderError, 'Broken.');

    $this->provisioning->provision($order);
    $this->provisioning->provision($order->fresh());

    $numbers = ProvisioningAttempt::query()
        ->where('order_id', $order->id)->orderBy('attempt_no')->pluck('attempt_no')->all();

    expect($numbers)->toBe([1, 2]);
});

it('lets the database refuse a duplicate attempt number', function (): void {
    $order = $this->floor->paidOrder();
    $this->provisioning->provision($order);

    $existing = ProvisioningAttempt::query()->firstOrFail();

    // Two workers counting "one so far" would both write attempt two. The
    // unique index is the arbiter, not the counting.
    expect(fn () => DB::transaction(fn () => DB::table('provisioning_attempts')->insert([
        'order_id' => $existing->order_id,
        'provisioning_uuid' => $existing->provisioning_uuid,
        'attempt_no' => $existing->attempt_no,
        'stage' => ProvisioningStage::Create->value,
        'outcome' => ProvisioningOutcome::InFlight->value,
        'started_at' => now(),
        'request_summary' => '{}',
        'created_at' => now(), 'updated_at' => now(),
    ])))->toThrow(QueryException::class);
});

it('refuses an attempt numbered zero', function (): void {
    $order = $this->floor->paidOrder();
    $prepared = $this->provisioning->prepare($order);

    expect(fn () => DB::transaction(fn () => DB::table('provisioning_attempts')->insert([
        'order_id' => $order->id,
        'provisioning_uuid' => $prepared->provisioning_uuid,
        'attempt_no' => 0,
        'stage' => ProvisioningStage::Create->value,
        'outcome' => ProvisioningOutcome::InFlight->value,
        'started_at' => now(),
        'request_summary' => '{}',
        'created_at' => now(), 'updated_at' => now(),
    ])))->toThrow(QueryException::class);
});

it('records the normalized category and never the provider prose', function (): void {
    $order = $this->floor->paidOrder();

    $scripted = Simulator::script();
    $scripted->rejectCreate(ProviderErrorCategory::RateLimited, 'Slow down, friend.');

    $this->provisioning->provision($order);

    $attempt = ProvisioningAttempt::query()->firstOrFail();

    expect($attempt->error_category)->toBe(ProviderErrorCategory::RateLimited)
        ->and($attempt->outcome)->toBe(ProvisioningOutcome::TransientFailure)
        // Business decisions come from the category. The message is not stored
        // here at all, so nothing can be tempted to parse it.
        ->and(json_encode($attempt->request_summary, JSON_THROW_ON_ERROR))
        ->not->toContain('Slow down')
        ->and(json_encode($attempt->result_summary ?? [], JSON_THROW_ON_ERROR))
        ->not->toContain('Slow down');
});

it('keeps an uncertain attempt uncertain, even after recovery succeeds', function (): void {
    $order = $this->floor->paidOrder();

    $scripted = Simulator::script();
    $scripted->loseCreateResponse();
    $this->provisioning->provision($order);

    $uncertain = ProvisioningAttempt::query()->orderBy('attempt_no')->firstOrFail();

    expect($uncertain->outcome)->toBe(ProvisioningOutcome::Uncertain);

    $scripted->afterCreate(fn ($server) => $server);
    app(ReconciliationService::class)->reconcile($order->fresh());

    // The first attempt is untouched. Rewriting it into a success would erase
    // the only evidence of why a customer waited.
    expect($uncertain->fresh()->outcome)->toBe(ProvisioningOutcome::Uncertain)
        ->and(ProvisioningAttempt::query()->count())->toBe(2)
        ->and(ProvisioningAttempt::query()->orderBy('attempt_no')->get()->last()->outcome)
        ->toBe(ProvisioningOutcome::RecoveredExisting);
});

it('records which stage a failure happened at', function (): void {
    $order = $this->floor->paidOrder();

    $scripted = Simulator::script();
    // An availability read that fails cannot have created anything, whatever
    // the category says.
    $scripted->onAvailability(function (): never {
        throw App\Cloud\Exceptions\ProviderException::make(
            ProviderErrorCategory::Timeout, App\Cloud\Fake\FakeProvider::CODE, 'No answer.',
        );
    });

    $this->provisioning->provision($order);

    $attempt = ProvisioningAttempt::query()->firstOrFail();

    expect($attempt->stage)->toBe(ProvisioningStage::BeforeCreate)
        ->and($attempt->stage->mayHaveCreatedRemotely())->toBeFalse()
        ->and($attempt->outcome)->toBe(ProvisioningOutcome::TransientFailure);
});

it('summarizes what was asked and what came back, and nothing else', function (): void {
    $order = $this->floor->paidOrder();
    $this->provisioning->provision($order);

    $attempt = ProvisioningAttempt::query()->firstOrFail();
    $request = $attempt->request_summary;
    $result = $attempt->result_summary;

    // Sorted: PostgreSQL jsonb does not preserve insertion order, so the set
    // of keys is the fact worth asserting.
    $requestKeys = array_keys($request);
    sort($requestKeys);

    expect($requestKeys)->toBe([
        'order_number', 'provider_code', 'provider_image_code',
        'provider_location_code', 'provider_plan_code', 'server_name',
    ]);

    expect($result)->toHaveKeys(['provider_server_id', 'provider_status', 'provider_power_state']);

    // A whitelist written out by hand is the only kind that stays a whitelist.
    $encoded = strtolower(json_encode([$request, $result], JSON_THROW_ON_ERROR));

    foreach (['password', 'authorization', 'api_key', 'secret', 'bearer'] as $forbidden) {
        expect($encoded)->not->toContain($forbidden);
    }
});

it('opens an attempt before the provider is called, not after', function (): void {
    $order = $this->floor->paidOrder();
    $seen = null;

    $scripted = Simulator::script();
    $scripted->beforeCreate(function () use ($order, &$seen): void {
        // A worker dying here must still leave evidence that a call was made.
        $seen = ProvisioningAttempt::query()->where('order_id', $order->getKey())->count();
    });

    $this->provisioning->provision($order);

    expect($seen)->toBe(1);
});

it('carries the order token on every attempt row', function (): void {
    $order = $this->floor->paidOrder();

    $scripted = Simulator::script();
    $scripted->rejectCreate(ProviderErrorCategory::TransientProviderError, 'Broken.');

    $this->provisioning->provision($order);
    $this->provisioning->provision($order->fresh());

    $token = $order->fresh()->provisioning_uuid;

    expect(ProvisioningAttempt::query()->pluck('provisioning_uuid')->unique()->all())
        ->toBe([$token]);
});
