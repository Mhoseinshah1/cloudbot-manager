<?php

declare(strict_types=1);

use App\Authorization\RoleProvisioner;
use App\Jobs\ProcessOutboxMessageJob;
use App\Models\NotificationLog;
use App\Models\OutboxMessage;
use App\Notifications\NotificationChannel;
use App\Notifications\NotificationStatus;
use App\Outbox\OutboxTopic;
use App\Outbox\OutboxWriter;
use Illuminate\Support\Facades\Http;
use Tests\Support\Telegram\BotFloor;

/**
 * An administrator channel that refuses us is not an alert we may throw away.
 *
 * Telegram answers 403 for two entirely different situations and the system has
 * to treat them as different. A customer's 403 is a person choosing to block
 * the bot: it is their decision, it is terminal, and retrying it forever is
 * arguing with somebody who left. An operator channel's 403 is a permission
 * somebody forgot to grant, a bot removed from a group, an administrator chat
 * that changed — a configuration fault, and one that gets fixed.
 *
 * The alert waiting behind it is the exact message that must survive that fix:
 * a failed provisioning, an inventory discrepancy, a provider rejecting our
 * credentials. Recording the refusal honestly and then marking the intent
 * processed discarded it permanently, so configuring the channel an hour later
 * delivered nothing at all.
 *
 * It is also not a deferral. Nothing was sent when no destination was
 * configured, so that attempt is handed back; here a request genuinely was made
 * and genuinely was refused, so the attempt stays spent and only the timing
 * moves — which is what keeps a permanently broken channel from retrying
 * without bound.
 */
beforeEach(function (): void {
    app(RoleProvisioner::class)->sync();

    $this->bot = BotFloor::open();

    config()->set('telegram.admin_chat_id', -1_001_234_567_890);
});

function runAdminOutboxJob(OutboxMessage $message): void
{
    app()->call([new ProcessOutboxMessageJob((int) $message->getKey()), 'handle']);
}

/** An operational alert waiting to reach whoever runs this installation. */
function pendingAdminAlert(): OutboxMessage
{
    $order = test()->bot->shop->paidOrder();

    OutboxMessage::query()
        ->where('topic', OutboxTopic::ProvisioningRequested)
        ->update(['processed_at' => now()]);

    return app(OutboxWriter::class)->record(
        OutboxTopic::ProvisioningFailed,
        $order,
        ['order_id' => $order->getKey(), 'category' => 'authentication'],
        'admin-refusal-alert',
    );
}

it('keeps a refused administrator alert alive and delivers it once the channel is fixed', function (): void {
    $alert = pendingAdminAlert();

    // One sequence for the whole test. `Http::fake()` appends its stubs and the
    // first match wins, so a second fake declared later would leave the 403
    // answering the delivery that is supposed to succeed at the end.
    Http::preventStrayRequests();
    Http::fake([
        '*' => Http::sequence()
            ->push(['ok' => false, 'error_code' => 403, 'description' => 'Forbidden: bot is not a member of the chat'])
            ->push(['ok' => true, 'result' => ['message_id' => 8801]]),
    ]);

    runAdminOutboxJob($alert);

    $afterRefusal = $alert->fresh();
    $failed = NotificationLog::query()
        ->where('channel', NotificationChannel::TelegramAdmin->value)
        ->where('status', NotificationStatus::Failed->value)
        ->get();

    expect($failed)->toHaveCount(1)
        // The attempt is recorded truthfully — this is the support history that
        // answers "why did nobody get told?".
        ->and($failed->first()->deduplication_key)->not->toBeNull()
        // And the intent is emphatically not finished.
        ->and($afterRefusal->processed_at)->toBeNull()
        // A request was made and refused, so the attempt stays spent. This is
        // the difference from a missing destination, where it is handed back.
        ->and($afterRefusal->attempts)->toBe(1)
        // Bounded, so a channel that never works is not a hot loop.
        ->and($afterRefusal->available_at->isAfter(now()->addMinutes(20)))->toBeTrue();

    $deferredUntil = $afterRefusal->available_at;
    $attempts = $afterRefusal->attempts;

    // A duplicate that was already in the queue when the barrier was written.
    // It must not call Telegram at all: releasing the worker that got the 403
    // says nothing about this one.
    runAdminOutboxJob($alert->fresh());

    $afterDuplicate = $alert->fresh();

    expect(Http::recorded()->count())->toBe(1)
        ->and(NotificationLog::query()->where('channel', NotificationChannel::TelegramAdmin->value)->count())->toBe(1)
        ->and($afterDuplicate->attempts)->toBe($attempts)
        ->and(abs($afterDuplicate->available_at->diffInSeconds($deferredUntil)))->toBeLessThanOrEqual(1)
        ->and($afterDuplicate->processed_at)->toBeNull();

    // Somebody grants the bot permission, and the message becomes due.
    OutboxMessage::query()->whereKey($alert->getKey())->update(['available_at' => now()->subMinute()]);

    runAdminOutboxJob($alert->fresh());

    $delivered = $alert->fresh();
    $sent = NotificationLog::query()
        ->where('channel', NotificationChannel::TelegramAdmin->value)
        ->where('status', NotificationStatus::Sent->value)
        ->get();

    expect(Http::recorded()->count())->toBe(2)
        // Exactly one successful delivery, recorded against the same intent.
        ->and($sent)->toHaveCount(1)
        // The same intent, recorded twice: once as the refusal and once as the
        // delivery. A failed row does not occupy the successful-delivery slot,
        // so the success still records against the key it belongs to.
        ->and($sent->first()->deduplication_key)->toBe($failed->first()->deduplication_key)
        ->and($delivered->processed_at)->not->toBeNull()
        ->and($delivered->attempts)->toBe(2)
        // The failed attempt is still there. It is history, not a mistake.
        ->and(NotificationLog::query()
            ->where('channel', NotificationChannel::TelegramAdmin->value)
            ->where('status', NotificationStatus::Failed->value)
            ->count())->toBe(1);
});

it('still finishes a missing administrator destination as a refunded deferral', function (): void {
    // The Part A behaviour, unchanged. No request was made, so the attempt is
    // handed back and the alert waits for somebody to configure a channel.
    config()->set('telegram.admin_chat_id', null);

    $alert = pendingAdminAlert();

    Http::preventStrayRequests();
    Http::fake([]);

    runAdminOutboxJob($alert);

    $after = $alert->fresh();

    expect($after->processed_at)->toBeNull()
        ->and($after->attempts)->toBe(0)
        ->and($after->available_at->isAfter(now()->addMinutes(20)))->toBeTrue()
        ->and(NotificationLog::query()
            ->where('status', NotificationStatus::Undeliverable->value)
            ->where('channel', NotificationChannel::TelegramAdmin->value)
            ->count())->toBe(1);

    Http::assertNothingSent();
});

it('gives up on a refused administrator channel once its durable attempts are spent', function (): void {
    $alert = pendingAdminAlert();

    Http::preventStrayRequests();
    Http::fake(['*' => Http::response(
        ['ok' => false, 'error_code' => 403, 'description' => 'Forbidden: bot is not a member of the chat'], 403,
    )]);

    $maximum = app(App\Outbox\OutboxDispatcher::class)->maximumAttempts();

    for ($attempt = 0; $attempt < $maximum + 2; $attempt++) {
        OutboxMessage::query()->whereKey($alert->getKey())->update(['available_at' => now()->subMinute()]);
        runAdminOutboxJob($alert->fresh());
    }

    $after = $alert->fresh();

    // Bounded, and never beyond the durable maximum. The row stays unprocessed
    // and visible, which is the right failure mode: an alert nobody delivered
    // can still be delivered, and one quietly discarded cannot.
    expect($after->attempts)->toBe($maximum)
        ->and($after->processed_at)->toBeNull()
        ->and(Http::recorded()->count())->toBe($maximum);
});
