<?php

declare(strict_types=1);

use App\Authorization\RoleProvisioner;
use App\Enums\OrderStatus;
use App\Jobs\ProcessOutboxMessageJob;
use App\Jobs\ProvisionOrderJob;
use App\Models\NotificationLog;
use App\Models\Order;
use App\Models\OutboxMessage;
use App\Models\Server;
use App\Models\Subscription;
use App\Models\TelegramAccount;
use App\Notifications\NotificationChannel;
use App\Notifications\NotificationStatus;
use App\Outbox\OutboxDispatcher;
use App\Outbox\OutboxTopic;
use App\Provisioning\ProvisioningService;
use App\Support\Queues;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Support\Telegram\BotFloor;

/**
 * Getting the news out, after the transaction that made it true.
 *
 * The gap this closes is specific and easy to miss. Dispatching a job right
 * after a commit works until the process dies in between — and an order sitting
 * at paid with no provisioning token is invisible to the stuck-provisioning
 * sweep, because that sweep looks for provisioning that started and stalled.
 * This one never started. The row in the outbox is what makes it findable.
 */
beforeEach(function (): void {
    app(RoleProvisioner::class)->sync();

    $this->bot = BotFloor::open();
    $this->dispatcher = app(OutboxDispatcher::class);
});

/**
 * A Telegram that accepts everything.
 *
 * Declared per test rather than once in beforeEach: `Http::fake()` appends its
 * stubs and the first match wins, so a test that needs Telegram to refuse
 * cannot override an accepting stub set up before it.
 */
function telegramAccepts(): void
{
    Http::fake(['api.telegram.test/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]])]);
}

function deliverOutbox(OutboxMessage $message): void
{
    app()->call([new ProcessOutboxMessageJob((int) $message->getKey()), 'handle']);
}

function requestedFor(Order $order): OutboxMessage
{
    return OutboxMessage::query()
        ->where('topic', OutboxTopic::ProvisioningRequested)
        ->where('aggregate_id', (string) $order->getKey())
        ->sole();
}

it('writes the promise to build inside the transaction that took the money', function (): void {
    $order = $this->bot->shop->paidOrder();

    $message = requestedFor($order);

    expect($order->status)->toBe(OrderStatus::Paid)
        ->and($message->processed_at)->toBeNull()
        ->and($message->payload['order_id'])->toBe($order->getKey());
});

it('provisions an order whose job was never dispatched', function (): void {
    // The exact crash this exists for: paid, committed, and then the process
    // died before anything was queued.
    $order = $this->bot->shop->paidOrder();

    expect(Server::query()->count())->toBe(0)
        ->and($order->fresh()->provisioning_uuid)->toBeNull();

    // Later, a sweep finds the promise nobody carried.
    expect($this->dispatcher->due()->pluck('id')->all())->toContain(requestedFor($order)->getKey());

    deliverOutbox(requestedFor($order));

    // The provisioning job is queued, on the queue built for waiting.
    expect(requestedFor($order)->fresh()->processed_at)->not->toBeNull();

    app()->call([new ProvisionOrderJob((int) $order->getKey()), 'handle']);

    expect(Server::query()->count())->toBe(1)
        ->and(Subscription::query()->count())->toBe(1)
        ->and(Order::query()->sole()->status)->toBe(OrderStatus::Provisioned);
});

it('dispatches provisioning onto the provisioning queue and sends nothing', function (): void {
    $order = $this->bot->shop->paidOrder();

    Queue::fake();
    deliverOutbox(requestedFor($order));

    Queue::assertPushedOn(Queues::Provisioning->value, ProvisionOrderJob::class);

    // No message to anybody: this topic is work to schedule, not news.
    expect(NotificationLog::query()->count())->toBe(0);
});

it('builds one server even when the promise is delivered twice', function (): void {
    $order = $this->bot->shop->paidOrder();
    $message = requestedFor($order);

    deliverOutbox($message);
    deliverOutbox($message);

    app()->call([new ProvisionOrderJob((int) $order->getKey()), 'handle']);
    app()->call([new ProvisionOrderJob((int) $order->getKey()), 'handle']);

    // One durable token yields one machine however many jobs arrive.
    expect(Server::query()->count())->toBe(1)
        ->and(App\Cloud\Fake\Models\FakeProviderServer::query()->count())->toBe(1);
});

it('tells the customer their server is ready', function (): void {
    $order = $this->bot->shop->paidOrder();
    deliverOutbox(requestedFor($order));
    app(ProvisioningService::class)->provision($order->fresh());

    telegramAccepts();

    $success = OutboxMessage::query()->where('topic', OutboxTopic::ProvisioningSucceeded)->sole();

    deliverOutbox($success);

    $server = Server::query()->sole();

    expect(BotFloor::transcript())->toContain($order->order_number)
        ->and(BotFloor::transcript())->toContain($server->name)
        ->and(NotificationLog::query()->sole()->status)->toBe(NotificationStatus::Sent)
        ->and(NotificationLog::query()->sole()->channel)->toBe(NotificationChannel::TelegramCustomer);
});

it('sends one message however many workers deliver it', function (): void {
    $order = $this->bot->shop->paidOrder();
    deliverOutbox(requestedFor($order));
    app(ProvisioningService::class)->provision($order->fresh());

    $success = OutboxMessage::query()->where('topic', OutboxTopic::ProvisioningSucceeded)->sole();

    telegramAccepts();

    deliverOutbox($success);
    deliverOutbox($success);
    deliverOutbox($success);

    // Marked processed on the first pass; the rest are no-ops.
    expect(NotificationLog::query()->count())->toBe(1)
        ->and($success->fresh()->processed_at)->not->toBeNull();
});

it('never puts a root password in a notification', function (): void {
    $password = 'Synthetic-'.bin2hex(random_bytes(12));

    $order = $this->bot->shop->paidOrder();
    deliverOutbox(requestedFor($order));
    app(ProvisioningService::class)->provision($order->fresh());

    Server::query()->sole()->forceFill(['root_password_encrypted' => $password])->save();

    telegramAccepts();

    deliverOutbox(OutboxMessage::query()->where('topic', OutboxTopic::ProvisioningSucceeded)->sole());

    expect(BotFloor::transcript())->not->toContain($password);

    $kept = json_encode([
        OutboxMessage::query()->get(['payload'])->toArray(),
        NotificationLog::query()->get(['summary'])->toArray(),
    ], JSON_THROW_ON_ERROR);

    expect($kept)->not->toContain($password);
});

it('waits exactly as long as telegram asked', function (): void {
    $order = $this->bot->shop->paidOrder();
    deliverOutbox(requestedFor($order));
    app(ProvisioningService::class)->provision($order->fresh());

    $success = OutboxMessage::query()->where('topic', OutboxTopic::ProvisioningSucceeded)->sole();

    Http::fake(['api.telegram.test/*' => Http::response([
        'ok' => false, 'error_code' => 429, 'description' => 'Too Many Requests',
        'parameters' => ['retry_after' => 11],
    ])]);

    $queueJob = Mockery::mock(Illuminate\Contracts\Queue\Job::class);
    $queueJob->shouldReceive('release')->once()->with(11);
    $queueJob->shouldReceive('getJobId')->andReturn('1');
    $queueJob->shouldReceive('hasFailed')->andReturnFalse();
    $queueJob->shouldReceive('isReleased')->andReturnTrue();
    $queueJob->shouldReceive('isDeleted')->andReturnFalse();
    $queueJob->shouldReceive('isDeletedOrReleased')->andReturnTrue();

    $job = new ProcessOutboxMessageJob((int) $success->getKey());
    $job->setJob($queueJob);
    app()->call([$job, 'handle']);

    // Emphatically not marked delivered: the message has not been sent.
    expect($success->fresh()->processed_at)->toBeNull()
        ->and(NotificationLog::query()->count())->toBe(0);
});

it('stops arguing with a customer who blocked the bot', function (): void {
    $order = $this->bot->shop->paidOrder();
    deliverOutbox(requestedFor($order));
    app(ProvisioningService::class)->provision($order->fresh());

    $success = OutboxMessage::query()->where('topic', OutboxTopic::ProvisioningSucceeded)->sole();

    Http::fake(['api.telegram.test/*' => Http::response([
        'ok' => false, 'error_code' => 403, 'description' => 'Forbidden: bot was blocked by the user',
    ])]);

    deliverOutbox($success);

    // Recorded against the account Telegram actually refused, and finished:
    // retrying cannot change somebody's mind.
    expect(NotificationLog::query()->sole()->status)->toBe(NotificationStatus::Blocked)
        ->and($success->fresh()->processed_at)->not->toBeNull()
        ->and(TelegramAccount::query()->sole()->bot_blocked_at)->not->toBeNull();
});

it('records an operational alert nobody can receive', function (): void {
    config()->set('telegram.admin_chat_id', null);

    $order = $this->bot->shop->paidOrder();

    $alert = app(App\Outbox\OutboxWriter::class)->record(
        OutboxTopic::ProvisioningFailed,
        $order,
        ['order_id' => $order->getKey(), 'category' => 'authentication'],
        'alert:'.$order->getKey(),
    );

    deliverOutbox($alert);

    // Answered so it does not retry forever, and recorded as undeliverable
    // rather than as sent — the log says plainly that nobody was told.
    expect(NotificationLog::query()->sole()->status)->toBe(NotificationStatus::Undeliverable)
        ->and(NotificationLog::query()->sole()->user_id)->toBeNull()
        ->and(NotificationLog::query()->sole()->channel)->toBe(NotificationChannel::TelegramAdmin);
});

it('sends an operational alert to the configured channel', function (): void {
    telegramAccepts();
    config()->set('telegram.admin_chat_id', -1_001_234_567_890);

    $order = $this->bot->shop->paidOrder();

    $alert = app(App\Outbox\OutboxWriter::class)->record(
        OutboxTopic::ProvisioningNeedsAttention,
        $order,
        ['order_id' => $order->getKey(), 'category' => 'uncertain_result'],
        'alert-attention:'.$order->getKey(),
    );

    deliverOutbox($alert);

    expect(NotificationLog::query()->sole()->status)->toBe(NotificationStatus::Sent)
        ->and(NotificationLog::query()->sole()->channel)->toBe(NotificationChannel::TelegramAdmin)
        // Identifiers and a topic. Never a provider's own words, which quote
        // back a request that carries credentials.
        ->and(BotFloor::transcript())->toContain(OutboxTopic::ProvisioningNeedsAttention)
        ->and(BotFloor::transcript())->toContain((string) $order->getKey());
});

it('leaves a topic nothing knows how to deliver unprocessed', function (): void {
    $order = $this->bot->shop->paidOrder();

    $unknown = app(App\Outbox\OutboxWriter::class)->record(
        'something.nobody.wrote',
        $order,
        ['order_id' => $order->getKey()],
        'unknown:'.$order->getKey(),
    );

    deliverOutbox($unknown);

    // A row that stays visible is a question somebody can answer; one marked
    // done is a message that silently never arrived.
    expect($unknown->fresh()->processed_at)->toBeNull()
        ->and($unknown->fresh()->attempts)->toBe(1);
});

it('stops retrying a message that will never work', function (): void {
    config()->set('cloudbot.outbox.max_attempts', 2);

    $order = $this->bot->shop->paidOrder();

    $unknown = app(App\Outbox\OutboxWriter::class)->record(
        'something.nobody.wrote',
        $order,
        ['order_id' => $order->getKey()],
        'bounded:'.$order->getKey(),
    );

    for ($i = 0; $i < 5; $i++) {
        deliverOutbox($unknown->fresh());
    }

    expect($unknown->fresh()->attempts)->toBe(2)
        // Still there, still unprocessed, and no longer swept up.
        ->and($this->dispatcher->due()->pluck('id')->all())->not->toContain($unknown->getKey());
});

it('sweeps in bounded batches, oldest first', function (): void {
    config()->set('cloudbot.outbox.dispatch_batch', 2);

    $order = $this->bot->shop->paidOrder();

    foreach (range(1, 5) as $index) {
        app(App\Outbox\OutboxWriter::class)->record(
            OutboxTopic::ProvisioningRequested,
            $order,
            ['order_id' => $order->getKey()],
            'batch:'.$index,
        );
    }

    // An unbounded query would pull a backlog into memory.
    expect($this->dispatcher->due()->count())->toBe(2);

    Queue::fake();
    expect($this->dispatcher->sweep())->toBe(2);
    Queue::assertPushedOn(Queues::Notifications->value, ProcessOutboxMessageJob::class);
});

it('offers an undelivered message again on the next sweep', function (): void {
    $order = $this->bot->shop->paidOrder();
    $message = requestedFor($order);

    // The queue lost the job. Nothing marked it processed, so it is still due.
    expect($this->dispatcher->due()->pluck('id')->all())->toContain($message->getKey());

    deliverOutbox($message);

    expect($this->dispatcher->due()->pluck('id')->all())->not->toContain($message->getKey());
});

it('runs notification work on its own queue', function (): void {
    // Isolated from both the interactive worker and the one building servers:
    // Telegram rate limits, and a message waiting ninety seconds must not be
    // waiting where a customer's tap is.
    expect(ProcessOutboxMessageJob::queueName())->toBe(Queues::Notifications->value)
        ->and(Queues::Notifications->value)->not->toBe(Queues::Telegram->value)
        ->and(Queues::Notifications->value)->not->toBe(Queues::Provisioning->value);
});
