<?php

declare(strict_types=1);

use App\Authorization\RoleProvisioner;
use App\Jobs\ProcessOutboxMessageJob;
use App\Models\NotificationLog;
use App\Models\OutboxMessage;
use App\Notifications\NotificationStatus;
use App\Outbox\OutboxDispatcher;
use App\Outbox\OutboxTopic;
use App\Outbox\OutboxWriter;
use App\Provisioning\ProvisioningService;
use Illuminate\Support\Facades\Http;
use Tests\Support\Telegram\BotFloor;

/**
 * A delay written to PostgreSQL has to hold against a job that predates it.
 *
 * Duplicate jobs for one outbox message are expected and deliberately safe — a
 * lost dispatch is repaired by re-offering the intent, and that only works if
 * offering it twice is harmless. But "harmless" has to include timing. A worker
 * that defers a message, or is told by Telegram to wait two minutes, releases
 * *itself*; a duplicate already sitting in Redis knows nothing about either and
 * would walk straight past the delay.
 *
 * So `available_at` is the authority, re-checked after the lock rather than only
 * when the sweep selects. These tests run the second job by hand, which is
 * exactly what a queue does when it delivers the duplicate that was already
 * there.
 */
beforeEach(function (): void {
    app(RoleProvisioner::class)->sync();

    $this->bot = BotFloor::open();
    $this->dispatcher = app(OutboxDispatcher::class);
});

function runOutboxJob(OutboxMessage $message): void
{
    // A separately constructed job, the way a second queued delivery arrives.
    app()->call([new ProcessOutboxMessageJob((int) $message->getKey()), 'handle']);
}

/** A provisioned order whose success intent is still waiting. */
function pendingSuccessIntent(): OutboxMessage
{
    $order = test()->bot->shop->paidOrder();

    OutboxMessage::query()
        ->where('topic', OutboxTopic::ProvisioningRequested)
        ->update(['processed_at' => now()]);

    app(ProvisioningService::class)->provision($order->fresh());

    return OutboxMessage::query()->where('topic', OutboxTopic::ProvisioningSucceeded)->sole();
}

it('will not let a prequeued duplicate bypass an admin deferral', function (): void {
    config()->set('telegram.admin_chat_id', null);

    $order = $this->bot->shop->paidOrder();

    $alert = app(OutboxWriter::class)->record(
        OutboxTopic::ProvisioningFailed,
        $order,
        ['order_id' => $order->getKey(), 'category' => 'authentication'],
        'timing-admin-alert',
    );

    Http::fake([]);

    // Job A defers it.
    runOutboxJob($alert);

    $afterFirst = $alert->fresh();

    expect($afterFirst->processed_at)->toBeNull()
        ->and($afterFirst->attempts)->toBe(0)
        ->and($afterFirst->available_at->isAfter(now()->addMinutes(20)))->toBeTrue();

    $deferredUntil = $afterFirst->available_at;
    $attemptsAfterFirst = $afterFirst->attempts;
    $logsAfterFirst = NotificationLog::query()->count();

    // Job B was already in the queue when that deferral was written. It knows
    // nothing about it.
    Http::preventStrayRequests();
    Http::fake([]);

    runOutboxJob($alert->fresh());

    Http::assertNothingSent();

    $afterSecond = $alert->fresh();

    expect(NotificationLog::query()->count())->toBe($logsAfterFirst)
        ->and($afterSecond->attempts)->toBe($attemptsAfterFirst)
        ->and(abs($afterSecond->available_at->diffInSeconds($deferredUntil)))->toBeLessThanOrEqual(1)
        ->and($afterSecond->processed_at)->toBeNull();
});

it('will not let a prequeued duplicate bypass a rate limit', function (): void {
    $intent = pendingSuccessIntent();

    // One sequence for the whole test: refused once, then accepted. Declared
    // once, because `Http::fake()` appends its stubs and the first match wins —
    // a second fake would leave the 429 answering the delivery that is supposed
    // to succeed at the end.
    Http::preventStrayRequests();
    Http::fake(['api.telegram.test/*' => Http::sequence()
        ->push([
            'ok' => false, 'error_code' => 429, 'description' => 'Too Many Requests',
            'parameters' => ['retry_after' => 120],
        ])
        ->whenEmpty(Http::response(['ok' => true, 'result' => ['message_id' => 1]]))]);

    // Job A is told to wait two minutes.
    runOutboxJob($intent);

    $afterFirst = $intent->fresh();

    expect($afterFirst->processed_at)->toBeNull()
        // Written to the row, not merely to this worker's release.
        ->and($afterFirst->available_at->isAfter(now()->addSeconds(110)))->toBeTrue()
        ->and($afterFirst->available_at->isBefore(now()->addSeconds(130)))->toBeTrue()
        // A request genuinely was made and refused, so the attempt is spent.
        ->and($afterFirst->attempts)->toBe(1)
        ->and(NotificationLog::query()->count())->toBe(0);

    $notBefore = $afterFirst->available_at;
    $sentSoFar = Http::recorded()->count();

    expect($sentSoFar)->toBe(1);

    // Job B was already queued. One more request is the defect, so the count
    // not moving is the assertion.
    runOutboxJob($intent->fresh());

    expect(Http::recorded()->count())->toBe($sentSoFar);

    $afterSecond = $intent->fresh();

    expect($afterSecond->attempts)->toBe(1)
        ->and(abs($afterSecond->available_at->diffInSeconds($notBefore)))->toBeLessThanOrEqual(1)
        ->and($afterSecond->processed_at)->toBeNull()
        ->and(NotificationLog::query()->count())->toBe(0);

    // Once it is genuinely due, delivery works normally. No sleeping: the
    // deadline is data, so the test moves the deadline.
    OutboxMessage::query()->whereKey($intent->getKey())->update(['available_at' => now()->subSecond()]);

    runOutboxJob($intent->fresh());

    expect($intent->fresh()->processed_at)->not->toBeNull()
        ->and(NotificationLog::query()->sole()->status)->toBe(NotificationStatus::Sent)
        // The refusal, then the delivery. Two requests in total, and the
        // second one only after the deadline passed.
        ->and(Http::recorded()->count())->toBe(2);
});

it('never shortens a deadline another worker already set', function (): void {
    $intent = pendingSuccessIntent();

    // Somebody already pushed it a long way out.
    $far = now()->addHour();
    OutboxMessage::query()->whereKey($intent->getKey())->update(['available_at' => $far]);

    $stored = $intent->fresh()->available_at;

    // A shorter postponement must not pull it back into view. Compared with a
    // second of tolerance: the column stores what PostgreSQL stores, and the
    // claim is that the deadline did not move, not that two Carbon objects
    // are bit-identical.
    $this->dispatcher->postpone($intent->fresh(), 60);

    expect(abs($intent->fresh()->available_at->diffInSeconds($stored)))->toBeLessThanOrEqual(1)
        ->and($intent->fresh()->available_at->isAfter(now()->addMinutes(50)))->toBeTrue();

    // A longer one moves it.
    $this->dispatcher->postpone($intent->fresh(), 7_200);

    expect($intent->fresh()->available_at->isAfter($stored))->toBeTrue();
});

it('refuses to spend an attempt on a message that is not due', function (): void {
    $intent = pendingSuccessIntent();

    OutboxMessage::query()->whereKey($intent->getKey())->update(['available_at' => now()->addHour()]);

    // The claim itself carries the condition, so no caller can skip it.
    expect($this->dispatcher->reserveAttempt($intent->fresh()))->toBeFalse()
        ->and($intent->fresh()->attempts)->toBe(0)
        ->and($this->dispatcher->isDue($intent->fresh()))->toBeFalse();

    OutboxMessage::query()->whereKey($intent->getKey())->update(['available_at' => now()->subSecond()]);

    expect($this->dispatcher->isDue($intent->fresh()))->toBeTrue()
        ->and($this->dispatcher->reserveAttempt($intent->fresh()))->toBeTrue()
        ->and($intent->fresh()->attempts)->toBe(1);
});

it('keeps a duplicate job harmless in every other state', function (): void {
    $intent = pendingSuccessIntent();

    Http::fake(['api.telegram.test/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]])]);

    runOutboxJob($intent);

    expect($intent->fresh()->processed_at)->not->toBeNull()
        ->and(Http::recorded()->count())->toBe(1);

    // A duplicate arriving after completion sends nothing: the processed check
    // catches it before the due check ever matters.
    Http::preventStrayRequests();
    Http::fake([]);

    runOutboxJob($intent->fresh());
    runOutboxJob($intent->fresh());

    Http::assertNothingSent();

    expect(NotificationLog::query()->count())->toBe(1);
});

it('leaves a not-yet-due message out of the sweep as well', function (): void {
    $intent = pendingSuccessIntent();

    OutboxMessage::query()->whereKey($intent->getKey())->update(['available_at' => now()->addHour()]);

    // Both halves agree: the sweep does not offer it, and a job that already
    // has it does nothing with it.
    expect($this->dispatcher->due()->pluck('id')->all())->not->toContain($intent->getKey());

    OutboxMessage::query()->whereKey($intent->getKey())->update(['available_at' => now()->subSecond()]);

    expect($this->dispatcher->due()->pluck('id')->all())->toContain($intent->getKey());
});
