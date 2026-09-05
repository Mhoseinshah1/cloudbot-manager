<?php

declare(strict_types=1);

use App\Authorization\RoleProvisioner;
use App\Jobs\ProcessOutboxMessageJob;
use App\Models\NotificationLog;
use App\Models\OutboxMessage;
use App\Models\TelegramAccount;
use App\Notifications\NotificationChannel;
use App\Notifications\NotificationStatus;
use App\Outbox\OutboxDispatcher;
use App\Outbox\OutboxRouter;
use App\Outbox\OutboxTopic;
use App\Outbox\OutboxWriter;
use App\Provisioning\ProvisioningService;
use Illuminate\Support\Facades\Http;
use Tests\Support\Telegram\BotFloor;

/**
 * Not sending twice, not sending to somebody who left, and not losing an alert.
 *
 * All three are about the same mistake in different clothes: treating a durable
 * record as bookkeeping rather than as something to consult before acting. A
 * unique index that only fires *after* the send has already let the send happen;
 * a blocked flag that is only written after a 403 has already made the request;
 * and an intent marked done because there was nowhere to deliver it is an alert
 * nobody will ever see.
 */
beforeEach(function (): void {
    app(RoleProvisioner::class)->sync();

    $this->bot = BotFloor::open();
});

function telegramSucceeds(): void
{
    Http::fake(['api.telegram.test/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]])]);
}

function deliverOnce(OutboxMessage $message): void
{
    app()->call([new ProcessOutboxMessageJob((int) $message->getKey()), 'handle']);
}

/** A paid, provisioned order with its success intent still unprocessed. */
function readyServerIntent(): OutboxMessage
{
    $order = test()->bot->shop->paidOrder();

    OutboxMessage::query()
        ->where('topic', OutboxTopic::ProvisioningRequested)
        ->update(['processed_at' => now()]);

    app(ProvisioningService::class)->provision($order->fresh());

    return OutboxMessage::query()->where('topic', OutboxTopic::ProvisioningSucceeded)->sole();
}

it('sends nothing when a durable record says it already did', function (): void {
    $intent = readyServerIntent();

    // The crash this exists for: Telegram accepted the message, the delivery
    // record committed, and the process died before the outbox was marked.
    NotificationLog::query()->create([
        'user_id' => $this->bot->customer()->getKey(),
        'outbox_message_id' => $intent->getKey(),
        'channel' => NotificationChannel::TelegramCustomer->value,
        'type' => OutboxTopic::ProvisioningSucceeded,
        'status' => NotificationStatus::Sent->value,
        'deduplication_key' => OutboxRouter::deliveryKey($intent),
        'summary' => ['order_id' => 1],
        'sent_at' => now(),
    ]);

    expect($intent->processed_at)->toBeNull();

    // Any request at all is the defect.
    Http::preventStrayRequests();
    Http::fake([]);

    deliverOnce($intent);

    Http::assertNothingSent();

    expect($intent->fresh()->processed_at)->not->toBeNull()
        // One record of one delivery. The history still says exactly what
        // happened: sent, once.
        ->and(NotificationLog::query()->where('status', NotificationStatus::Sent->value)->count())->toBe(1);
});

it('sends nothing to a customer who has already blocked the bot', function (): void {
    $first = readyServerIntent();

    // Their first notification is refused, and Phase 8's flag is written.
    Http::fake(['api.telegram.test/*' => Http::response([
        'ok' => false, 'error_code' => 403, 'description' => 'Forbidden: bot was blocked by the user',
    ])]);

    deliverOnce($first);

    expect(TelegramAccount::query()->sole()->bot_blocked_at)->not->toBeNull()
        ->and(NotificationLog::query()->sole()->status)->toBe(NotificationStatus::Blocked);

    // A second, genuinely different intent for the same customer.
    $second = app(OutboxWriter::class)->record(
        OutboxTopic::ServerTerminated,
        App\Models\Server::query()->sole(),
        [
            'server_id' => App\Models\Server::query()->sole()->getKey(),
            'server_name' => App\Models\Server::query()->sole()->name,
        ],
        'blocked-second-intent',
    );

    Http::preventStrayRequests();
    Http::fake([]);

    deliverOnce($second);

    // Not one request. Telegram would refuse it, and asking to be told what we
    // already know is the avoidable part.
    Http::assertNothingSent();

    expect($second->fresh()->processed_at)->not->toBeNull()
        ->and(NotificationLog::query()->where('status', NotificationStatus::Blocked->value)->count())->toBe(2);
});

it('sends again once the customer comes back', function (): void {
    $first = readyServerIntent();

    // One sequence for the whole test: the notification is refused, and
    // everything after it is accepted. Declared once, because `Http::fake()`
    // appends its stubs and the first match wins — a second fake would leave
    // the 403 answering the customer's own greeting, which would mark them
    // blocked again and hide what this test is about.
    Http::fake(['api.telegram.test/*' => Http::sequence()
        ->push(['ok' => false, 'error_code' => 403, 'description' => 'Forbidden: bot was blocked by the user'])
        ->whenEmpty(Http::response(['ok' => true, 'result' => ['message_id' => 1]]))]);

    deliverOnce($first);

    expect(TelegramAccount::query()->sole()->bot_blocked_at)->not->toBeNull();

    // Phase 8 clears the flag: an inbound private message from that identity is
    // proof the bot is reachable again. A group message is not.
    $this->bot->deliver([
        'update_id' => 990_001,
        'message' => [
            'message_id' => 5,
            'from' => ['id' => BotFloor::TELEGRAM_USER_ID, 'is_bot' => false, 'first_name' => 'مریم'],
            'chat' => ['id' => -100_123, 'type' => 'supergroup'],
            'text' => '/start',
        ],
    ]);

    expect(TelegramAccount::query()->sole()->bot_blocked_at)->not->toBeNull();

    $this->bot->say('/start', 990_002);

    expect(TelegramAccount::query()->sole()->bot_blocked_at)->toBeNull();

    // And a later notification reaches them normally.
    $second = app(OutboxWriter::class)->record(
        OutboxTopic::ServerTerminated,
        App\Models\Server::query()->sole(),
        [
            'server_id' => App\Models\Server::query()->sole()->getKey(),
            'server_name' => App\Models\Server::query()->sole()->name,
        ],
        'unblocked-intent',
    );

    deliverOnce($second);

    expect($second->fresh()->processed_at)->not->toBeNull()
        ->and(NotificationLog::query()->where('status', NotificationStatus::Sent->value)->count())->toBe(1);
});

it('keeps an operational alert that has nowhere to go', function (): void {
    config()->set('telegram.admin_chat_id', null);

    $order = $this->bot->shop->paidOrder();

    $alert = app(OutboxWriter::class)->record(
        OutboxTopic::ProvisioningFailed,
        $order,
        ['order_id' => $order->getKey(), 'category' => 'authentication'],
        'alert-deferred',
    );

    Http::preventStrayRequests();
    Http::fake([]);

    deliverOnce($alert);

    Http::assertNothingSent();

    $after = $alert->fresh();

    expect($after->processed_at)->toBeNull()
        // Deferred rather than spun, and the attempt handed back: a
        // configuration gap must not exhaust the budget of an alert nobody has
        // yet had the chance to receive.
        ->and($after->available_at->timestamp)->toBeGreaterThan(now()->addMinutes(20)->timestamp)
        ->and($after->attempts)->toBe(0)
        // And the history says honestly that nobody was told.
        ->and(NotificationLog::query()->sole()->status)->toBe(NotificationStatus::Undeliverable)
        ->and(NotificationLog::query()->sole()->channel)->toBe(NotificationChannel::TelegramAdmin)
        ->and(NotificationLog::query()->sole()->user_id)->toBeNull();

    // Nobody invented an account for the alert channel.
    expect(App\Models\User::query()->count())->toBe(2)
        ->and(TelegramAccount::query()->count())->toBe(1);
});

it('delivers a deferred alert once the destination is configured', function (): void {
    config()->set('telegram.admin_chat_id', null);

    $order = $this->bot->shop->paidOrder();

    $alert = app(OutboxWriter::class)->record(
        OutboxTopic::ProvisioningNeedsAttention,
        $order,
        ['order_id' => $order->getKey(), 'category' => 'uncertain_result'],
        'alert-recovered',
    );

    Http::fake([]);
    deliverOnce($alert);

    expect($alert->fresh()->processed_at)->toBeNull();

    // An operator configures the channel, and the waiting alert comes due.
    config()->set('telegram.admin_chat_id', -1_001_234_567_890);
    OutboxMessage::query()->whereKey($alert->getKey())->update(['available_at' => now()->subMinute()]);

    expect(app(OutboxDispatcher::class)->due()->pluck('id')->all())->toContain($alert->getKey());

    telegramSucceeds();

    deliverOnce($alert->fresh());

    expect(Http::recorded()->count())->toBe(1)
        ->and($alert->fresh()->processed_at)->not->toBeNull()
        // Support history keeps both the attempt that had nowhere to go and
        // the delivery that eventually worked.
        ->and(NotificationLog::query()->where('status', NotificationStatus::Undeliverable->value)->count())->toBe(1)
        ->and(NotificationLog::query()->where('status', NotificationStatus::Sent->value)->count())->toBe(1);
});

it('still waits exactly as long as telegram asks', function (): void {
    // The 429 path, retested because the delivery semantics around it changed.
    $intent = readyServerIntent();

    Http::fake(['api.telegram.test/*' => Http::response([
        'ok' => false, 'error_code' => 429, 'description' => 'Too Many Requests',
        'parameters' => ['retry_after' => 13],
    ])]);

    $queueJob = Mockery::mock(Illuminate\Contracts\Queue\Job::class);
    $queueJob->shouldReceive('release')->once()->with(13);
    $queueJob->shouldReceive('getJobId')->andReturn('1');
    $queueJob->shouldReceive('hasFailed')->andReturnFalse();
    $queueJob->shouldReceive('isReleased')->andReturnTrue();
    $queueJob->shouldReceive('isDeleted')->andReturnFalse();
    $queueJob->shouldReceive('isDeletedOrReleased')->andReturnTrue();

    $job = new ProcessOutboxMessageJob((int) $intent->getKey());
    $job->setJob($queueJob);
    app()->call([$job, 'handle']);

    // Not delivered, not marked, and no false record of a send.
    expect($intent->fresh()->processed_at)->toBeNull()
        ->and(NotificationLog::query()->where('status', NotificationStatus::Sent->value)->count())->toBe(0)
        ->and(NotificationLog::query()->count())->toBe(0);
});
