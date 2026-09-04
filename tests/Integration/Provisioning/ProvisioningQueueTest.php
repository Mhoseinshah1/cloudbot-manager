<?php

declare(strict_types=1);

use App\Authorization\RoleProvisioner;
use App\Jobs\ProvisionOrderJob;
use App\Models\Server;
use App\Provisioning\Exceptions\InvalidLockTopology;
use App\Provisioning\ProvisioningLock;
use App\Support\Queues;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Tests\Support\Provisioning\ProvisioningFloor;
use Tests\Support\Provisioning\Simulator;

/**
 * Where provisioning work runs, and what coordinates it.
 *
 * The queue choice is a correctness property. A provider create can block for
 * minutes; sharing a worker with interactive Telegram traffic would put every
 * customer pressing a button behind somebody else's server being built.
 *
 * The lock is not a correctness property, and the tests say so: it coordinates,
 * while the durable token, the provider's idempotency and the unique
 * constraints are what actually prevent a duplicate machine.
 */
beforeEach(function (): void {
    app(RoleProvisioner::class)->sync();

    $this->floor = ProvisioningFloor::open();
});

it('runs only on the dedicated provisioning queue', function (): void {
    // Pushed for real, onto the real Redis queue. A faked dispatcher would only
    // prove what the job says about itself; this proves where it lands.
    // Redis outlives a rolled-back database transaction, so every queue is
    // emptied first rather than assumed empty.
    // Redis outlives a rolled-back database transaction, so every queue is
    // emptied first rather than assumed empty.
    foreach (Queues::names() as $queue) {
        Queue::clear($queue);
    }

    $order = $this->floor->paidOrder();
    ProvisionOrderJob::dispatch((int) $order->getKey());

    expect(Queue::size(Queues::Provisioning->value))->toBe(1)
        // Interactive work must never wait behind a provider create, and a
        // provider create must never be drained by a worker with a short
        // timeout.
        ->and(Queue::size(Queues::Telegram->value))->toBe(0)
        ->and(Queue::size(Queues::Default->value))->toBe(0)
        ->and(Queue::size(Queues::Notifications->value))->toBe(0)
        ->and(ProvisionOrderJob::queueName())->toBe(Queues::Provisioning->value);

    Queue::clear(Queues::Provisioning->value);
});

it('is drained by the provisioning worker and by no other', function (): void {
    // The Compose topology and the job have to agree: a queue nothing drains
    // is a customer whose server is never built.
    $compose = (string) file_get_contents(base_path('compose.yaml'));

    expect($compose)->toContain('--queue='.Queues::Provisioning->value)
        // And the interactive worker must not also drain it.
        ->and($compose)->toContain('--queue=telegram,default')
        ->and($compose)->not->toContain('--queue=telegram,default,provisioning');
});

it('carries an order id and nothing a payload should not hold', function (): void {
    $order = $this->floor->paidOrder();
    $job = new ProvisionOrderJob((int) $order->getKey());

    // A job payload is serialized into Redis, read by anything that can reach
    // it, and printed whole in a failed-job record.
    $payload = serialize($job);

    expect($job->orderId)->toBe((int) $order->getKey())
        ->and(strtolower($payload))->not->toContain('password')
        ->and(strtolower($payload))->not->toContain('authorization')
        ->and(strtolower($payload))->not->toContain('api_key')
        // Not the provider object, not credentials — everything is re-read
        // from PostgreSQL, which is where the truth is anyway.
        ->and($payload)->not->toContain('FakeProvider');
});

it('backs off approximately 30s, 120s and then 600s', function (): void {
    $job = new ProvisionOrderJob(1);

    expect($job->tries)->toBe(3)
        ->and($job->backoff())->toBe([30, 120, 600]);
});

it('builds the server when the job actually runs', function (): void {
    Simulator::script();

    $order = $this->floor->paidOrder();
    (new ProvisionOrderJob((int) $order->getKey()))->handle(app(App\Provisioning\ProvisioningService::class));

    expect($order->fresh()->status->value)->toBe('provisioned')
        ->and(Server::query()->count())->toBe(1);
});

it('refuses a lock that could expire mid-call', function (): void {
    // The rule exists because the failure is silent: a lock shorter than the
    // call it covers is taken by a second worker who believes it is alone.
    expect(fn () => ProvisioningLock::assertTopology(100, 120))
        ->toThrow(InvalidLockTopology::class);

    expect(fn () => ProvisioningLock::assertTopology(239, 120))
        ->toThrow(InvalidLockTopology::class);

    expect(fn () => ProvisioningLock::assertTopology(300, 0))
        ->toThrow(InvalidLockTopology::class);

    // Exactly twice is the stated minimum, and is accepted.
    ProvisioningLock::assertTopology(240, 120);
    ProvisioningLock::assertTopology(300, 120);

    expect(true)->toBeTrue();
});

it('ships a configuration that satisfies its own rule', function (): void {
    $ttl = (int) config('cloudbot.provisioning.lock_ttl_seconds');
    $timeout = (int) config('cloudbot.provisioning.provider_timeout_seconds');

    expect($ttl)->toBeGreaterThanOrEqual(2 * $timeout)
        ->and(app(ProvisioningLock::class)->ttlSeconds())->toBe($ttl);
});

it('names a lock deterministically, per order', function (): void {
    $order = $this->floor->paidOrder();

    expect(ProvisioningLock::keyFor($order))->toBe('provisioning:order:'.$order->getKey())
        // Same order, same key, every time — a per-worker or per-attempt key
        // would coordinate nothing.
        ->and(ProvisioningLock::keyFor($order->fresh()))->toBe(ProvisioningLock::keyFor($order));
});

it('makes no provider call while another worker holds the lock', function (): void {
    $order = $this->floor->paidOrder();
    $scripted = Simulator::script();

    // Somebody else is already working on this order.
    $held = Cache::store('locks')->lock(ProvisioningLock::keyFor($order), 60);
    expect($held->get())->toBeTrue();

    $result = app(App\Provisioning\ProvisioningService::class)->provision($order);

    expect($result->state)->toBe(App\Provisioning\Data\ProvisioningResult::Contended)
        // Not one call. Contention is a reason to wait, never to guess.
        ->and($scripted->calls)->toBe([])
        ->and(Server::query()->count())->toBe(0);

    $held->release();

    // And it proceeds once the lock is free.
    $after = app(App\Provisioning\ProvisioningService::class)->provision($order->fresh());

    expect($after->state)->toBe(App\Provisioning\Data\ProvisioningResult::Provisioned);
});

it('keeps its locks in the dedicated store a cache flush cannot reach', function (): void {
    $order = $this->floor->paidOrder();
    $lock = Cache::store('locks')->lock(ProvisioningLock::keyFor($order), 60);

    expect($lock->get())->toBeTrue();

    // A routine cache flush must not release a lock covering a provider call
    // that is still in flight.
    Cache::store('redis')->clear();

    $rival = Cache::store('locks')->lock(ProvisioningLock::keyFor($order), 60);

    expect($rival->get())->toBeFalse();

    $lock->release();
});
