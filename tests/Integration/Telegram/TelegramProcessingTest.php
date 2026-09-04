<?php

declare(strict_types=1);

use App\Enums\TelegramUpdateStatus;
use App\Enums\UserStatus;
use App\Jobs\ProcessTelegramUpdateJob;
use App\Models\TelegramAccount;
use App\Models\TelegramUpdate;
use App\Models\User;
use App\Support\Queues;
use App\Telegram\TelegramUpdateNormalizer;
use App\Telegram\TelegramUpdateRecorder;
use Illuminate\Cache\Repository;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Tests\Support\Cache\LocklessStore;

/**
 * Handling one update, on the interactive worker.
 *
 * The work itself is small on purpose — identify a customer, send a menu — but
 * the machinery around it is the machinery that will later carry purchases, so
 * it is proven now while a duplicate can only repeat a greeting.
 */
beforeEach(function (): void {
    config()->set('telegram.api_base_url', 'https://api.telegram.test');
    config()->set('telegram.bot_token', '11'.random_int(1_000_000, 9_999_999).':AA'.bin2hex(random_bytes(12)));

    Http::preventStrayRequests();

    foreach (Queues::names() as $queue) {
        Queue::clear($queue);
    }
});

function telegramOk(array $result = ['message_id' => 1]): void
{
    Http::fake(['api.telegram.test/*' => Http::response(['ok' => true, 'result' => $result])]);
}

function recordUpdate(array $payload): TelegramUpdate
{
    $normalized = app(TelegramUpdateNormalizer::class)->normalize($payload);

    return app(TelegramUpdateRecorder::class)->record($normalized)['update'];
}

function startPayload(int $updateId = 700100, int $userId = 5500123456, array $from = []): array
{
    return [
        'update_id' => $updateId,
        'message' => [
            'message_id' => 3,
            'from' => ['id' => $userId, 'is_bot' => false, 'first_name' => 'مریم', ...$from],
            'chat' => ['id' => $userId, 'type' => 'private'],
            'text' => '/start',
        ],
    ];
}

function runJob(TelegramUpdate $update): void
{
    app()->call([new ProcessTelegramUpdateJob((int) $update->getKey()), 'handle']);
}

it('greets a new customer and records the update as processed', function (): void {
    telegramOk();
    $update = recordUpdate(startPayload());

    runJob($update);

    $fresh = $update->fresh();

    expect($fresh->status)->toBe(TelegramUpdateStatus::Processed)
        ->and($fresh->processed_at)->not->toBeNull()
        ->and(User::query()->count())->toBe(1)
        ->and(TelegramAccount::query()->count())->toBe(1);

    Http::assertSent(function (Request $request): bool {
        return str_ends_with($request->url(), '/sendMessage')
            && str_contains((string) $request['text'], 'خوش آمدید');
    });
});

it('does nothing the second time it runs for one update', function (): void {
    telegramOk();
    $update = recordUpdate(startPayload());

    runJob($update);
    $processedAt = $update->fresh()->processed_at->toIso8601String();

    // Duplicated dispatch, worker restart, Telegram retry — all the same here.
    runJob($update);
    runJob($update);

    expect(User::query()->count())->toBe(1)
        ->and(TelegramAccount::query()->count())->toBe(1)
        ->and($update->fresh()->processed_at->toIso8601String())->toBe($processedAt)
        // One greeting, not three.
        ->and(Http::recorded()->count())->toBe(1);
});

it('marks the update processed only after the work succeeded', function (): void {
    Http::fake(['api.telegram.test/*' => Http::response(['ok' => false, 'error_code' => 400, 'description' => 'nope'])]);

    $update = recordUpdate(startPayload());

    expect(fn () => runJob($update))->toThrow(App\Telegram\Exceptions\TelegramRejected::class);

    $fresh = $update->fresh();

    expect($fresh->status)->toBe(TelegramUpdateStatus::Failed)
        ->and($fresh->processed_at)->toBeNull()
        ->and($fresh->failure_reason)->toBe('telegram_api_error')
        // The row is kept, so the dedup record survives and a retry is possible.
        ->and(TelegramUpdate::query()->count())->toBe(1);
});

it('waits exactly as long as telegram asked, without a hot loop', function (): void {
    Http::fake(['api.telegram.test/*' => Http::response([
        'ok' => false, 'error_code' => 429, 'description' => 'Too Many Requests',
        'parameters' => ['retry_after' => 7],
    ])]);

    $update = recordUpdate(startPayload());

    // The job is final, so the release is observed through the queue job it
    // would really be running under — which is what actually carries the delay.
    $queueJob = Mockery::mock(Illuminate\Contracts\Queue\Job::class);
    $queueJob->shouldReceive('release')->once()->with(7);
    $queueJob->shouldReceive('getJobId')->andReturn('1');
    $queueJob->shouldReceive('hasFailed')->andReturnFalse();
    $queueJob->shouldReceive('isReleased')->andReturnTrue();
    $queueJob->shouldReceive('isDeleted')->andReturnFalse();
    $queueJob->shouldReceive('isDeletedOrReleased')->andReturnTrue();

    $job = new ProcessTelegramUpdateJob((int) $update->getKey());
    $job->setJob($queueJob);

    app()->call([$job, 'handle']);

    // One call, not a retry storm — and the update is emphatically not done.
    expect(Http::recorded()->count())->toBe(1)
        ->and($update->fresh()->status)->toBe(TelegramUpdateStatus::Failed)
        ->and($update->fresh()->processed_at)->toBeNull();
});

it('marks the right account blocked and stops retrying', function (): void {
    // One sequence for the whole test: two customers greeted, then the first
    // one blocks the bot.
    Http::fake(['api.telegram.test/*' => Http::sequence()
        ->push(['ok' => true, 'result' => ['message_id' => 1]])
        ->push(['ok' => true, 'result' => ['message_id' => 2]])
        ->push(['ok' => false, 'error_code' => 403, 'description' => 'Forbidden: bot was blocked by the user'])]);

    $update = recordUpdate(startPayload(700200, 5500123456));
    runJob($update);

    // A second, unrelated customer who must not be affected.
    $other = recordUpdate(startPayload(700201, 6600987654));
    runJob($other);

    expect(TelegramAccount::query()->count())->toBe(2);

    $blockedUpdate = recordUpdate(startPayload(700202, 5500123456));
    runJob($blockedUpdate);

    $blocked = TelegramAccount::query()->where('telegram_user_id', 5500123456)->firstOrFail();
    $untouched = TelegramAccount::query()->where('telegram_user_id', 6600987654)->firstOrFail();

    expect($blocked->bot_blocked_at)->not->toBeNull()
        ->and($blocked->hasBlockedBot())->toBeTrue()
        // Identity, not username: the other account is untouched.
        ->and($untouched->bot_blocked_at)->toBeNull()
        // Finished rather than retried forever: they will not unblock because
        // a queue kept trying.
        ->and($blockedUpdate->fresh()->status)->toBe(TelegramUpdateStatus::Processed);
});

it('clears a stale blocked flag when the customer comes back', function (): void {
    telegramOk();
    $update = recordUpdate(startPayload(700300));
    runJob($update);

    $account = TelegramAccount::query()->firstOrFail();
    $account->forceFill(['bot_blocked_at' => now()->subDay()])->save();

    // Telegram just delivered a private message from this identity, which is
    // proof the bot is reachable again.
    $return = recordUpdate(startPayload(700301));
    runJob($return);

    expect($account->fresh()->bot_blocked_at)->toBeNull();
});

it('marks them blocked again if the next send is still refused', function (): void {
    Http::fake(['api.telegram.test/*' => Http::sequence()
        ->push(['ok' => true, 'result' => ['message_id' => 1]])
        ->push(['ok' => false, 'error_code' => 403, 'description' => 'Forbidden: bot was blocked by the user'])]);

    runJob(recordUpdate(startPayload(700400)));

    $account = TelegramAccount::query()->firstOrFail();
    $account->forceFill(['bot_blocked_at' => now()->subDay()])->save();

    runJob(recordUpdate(startPayload(700401)));

    expect($account->fresh()->bot_blocked_at)->not->toBeNull();
});

it('stops the button spinner before doing anything else', function (): void {
    Http::fake(['api.telegram.test/*' => Http::sequence()
        ->push(['ok' => true, 'result' => true])
        ->push(['ok' => true, 'result' => ['message_id' => 1]])]);

    // A customer exists already, so identification is not what is being timed.
    $update = recordUpdate([
        'update_id' => 700500,
        'callback_query' => [
            'id' => 'cbq-123',
            'from' => ['id' => 5500123456, 'is_bot' => false, 'first_name' => 'مریم'],
            'message' => ['message_id' => 8, 'chat' => ['id' => 5500123456, 'type' => 'private']],
            'data' => 'menu:help',
        ],
    ]);

    runJob($update);

    $calls = Http::recorded()->map(fn (array $pair): string => (string) $pair[0]->url())->all();

    // Acknowledged first, and only then the work.
    expect($calls[0])->toEndWith('/answerCallbackQuery')
        ->and($calls[1])->toEndWith('/sendMessage')
        ->and($update->fresh()->status)->toBe(TelegramUpdateStatus::Processed);
});

it('acknowledges a button that no longer means anything, and does nothing else', function (): void {
    Http::fake(['api.telegram.test/*' => Http::sequence()
        ->push(['ok' => true, 'result' => true])
        ->push(['ok' => true, 'result' => ['message_id' => 1]])]);

    $update = recordUpdate([
        'update_id' => 700600,
        'callback_query' => [
            'id' => 'cbq-expired',
            'from' => ['id' => 5500123456, 'is_bot' => false],
            'message' => ['message_id' => 8, 'chat' => ['id' => 5500123456, 'type' => 'private']],
            // Not a form this phase recognises, and not trusted for anything.
            'data' => 'order:99:confirm:pay',
        ],
    ]);

    runJob($update);

    Http::assertSent(fn (Request $r): bool => str_ends_with($r->url(), '/answerCallbackQuery'));

    // No order, no wallet movement, no server. Callback data is not authority.
    expect(App\Models\Order::query()->count())->toBe(0)
        ->and($update->fresh()->action->value)->toBe('unknown')
        ->and($update->fresh()->status)->toBe(TelegramUpdateStatus::Processed);
});

it('answers a not-yet-built menu entry without changing anything', function (): void {
    telegramOk();
    runJob(recordUpdate(startPayload(700700)));

    $update = recordUpdate([
        'update_id' => 700701,
        'message' => [
            'message_id' => 4,
            'from' => ['id' => 5500123456, 'is_bot' => false],
            'chat' => ['id' => 5500123456, 'type' => 'private'],
            'text' => 'خرید سرور',
        ],
    ]);

    runJob($update);

    expect($update->fresh()->action->value)->toBe('menu.buy_server')
        ->and(App\Models\Order::query()->count())->toBe(0)
        ->and(App\Models\Server::query()->count())->toBe(0);

    Http::assertSent(function (Request $request): bool {
        $text = (string) $request['text'];

        // A sentence in the customer's language, and nothing about phases.
        return str_ends_with($request->url(), '/sendMessage')
            && str_contains($text, 'فعال نشده')
            && ! str_contains(strtolower($text), 'phase');
    });
});

it('refuses to greet a banned customer or revive them', function (): void {
    telegramOk();
    runJob(recordUpdate(startPayload(700800)));

    $account = TelegramAccount::query()->firstOrFail();
    $account->user->forceFill(['status' => UserStatus::Banned])->save();

    runJob(recordUpdate(startPayload(700801)));

    expect($account->user->fresh()->status)->toBe(UserStatus::Banned);

    Http::assertSent(function (Request $request): bool {
        return str_contains((string) $request['text'], 'فعال نیست');
    });
});

it('never replies into a group or lets one hijack a customer chat', function (): void {
    telegramOk();
    runJob(recordUpdate(startPayload(700900, 5500123456)));

    $account = TelegramAccount::query()->firstOrFail();
    $privateChat = $account->telegram_chat_id;
    $greetings = Http::recorded()->count();

    // The same person, talking in a group the bot happens to be in.
    $groupUpdate = recordUpdate([
        'update_id' => 700901,
        'message' => [
            'message_id' => 9,
            'from' => ['id' => 5500123456, 'is_bot' => false],
            'chat' => ['id' => -1001234567890, 'type' => 'supergroup'],
            'text' => '/start',
        ],
    ]);

    runJob($groupUpdate);

    // Their invoices and credentials still go to their own conversation.
    expect($account->fresh()->telegram_chat_id)->toBe($privateChat)
        ->and($account->fresh()->telegram_chat_id)->not->toBe(-1001234567890)
        ->and($groupUpdate->fresh()->status)->toBe(TelegramUpdateStatus::Processed)
        // Nothing was sent to the group, or to anyone, for that update.
        ->and(Http::recorded()->count())->toBe($greetings);
});

it('ignores another bot entirely', function (): void {
    Http::fake();

    $update = recordUpdate(startPayload(701000, 4400111222, ['is_bot' => true]));
    runJob($update);

    expect(User::query()->count())->toBe(0)
        ->and(TelegramAccount::query()->count())->toBe(0)
        ->and($update->fresh()->status)->toBe(TelegramUpdateStatus::Processed);

    Http::assertNothingSent();
});

it('keeps the bot token out of anything it logs', function (): void {
    $token = (string) config('telegram.bot_token');
    $lines = [];
    Log::listen(function (object $entry) use (&$lines): void {
        $lines[] = $entry->message.' '.json_encode($entry->context, JSON_THROW_ON_ERROR);
    });

    Http::fake(['api.telegram.test/*' => Http::response([
        'ok' => false, 'error_code' => 400, 'description' => "failed calling /bot{$token}/sendMessage",
    ])]);

    try {
        runJob(recordUpdate(startPayload(701100)));
    } catch (Throwable) {
        // What was logged is the point.
    }

    expect(implode(' ', $lines))->not->toContain($token);
});

it('carries only an identifier in its queue payload', function (): void {
    $update = recordUpdate(startPayload(701200));
    $job = new ProcessTelegramUpdateJob((int) $update->getKey());

    $payload = serialize($job);

    expect($job->telegramUpdateId)->toBe((int) $update->getKey())
        ->and(strtolower($payload))->not->toContain('password')
        ->and(strtolower($payload))->not->toContain('authorization')
        // Not the webhook body, and not a credential.
        ->and($payload)->not->toContain('/start')
        ->and($payload)->not->toContain((string) config('telegram.bot_token'));
});

it('runs on the telegram queue and nowhere else', function (): void {
    $update = recordUpdate(startPayload(701300));

    $pending = ProcessTelegramUpdateJob::dispatch((int) $update->getKey());
    unset($pending);

    expect(Queue::size(Queues::Telegram->value))->toBe(1)
        ->and(Queue::size(Queues::Provisioning->value))->toBe(0)
        ->and(Queue::size(Queues::Notifications->value))->toBe(0)
        ->and(Queue::size(Queues::Default->value))->toBe(0)
        ->and(ProcessTelegramUpdateJob::queueName())->toBe('telegram');

    Queue::clear(Queues::Telegram->value);
});

/**
 * The lock this job really uses: the configured `locks` store, the real key.
 *
 * Built the same checked way the job builds it, so a test that holds this lock
 * is genuinely contending with the job rather than with a lookalike.
 */
function telegramUpdateLock(TelegramUpdate $update): Lock
{
    $store = Cache::store('locks')->getStore();

    expect($store)->toBeInstanceOf(LockProvider::class);

    return $store->lock('telegram:update:'.$update->getKey(), 30);
}

/** A queue job that expects to be released once, for exactly this long. */
function releasableJob(int $seconds): Illuminate\Contracts\Queue\Job
{
    $queueJob = Mockery::mock(Illuminate\Contracts\Queue\Job::class);
    $queueJob->shouldReceive('release')->once()->with($seconds);
    $queueJob->shouldReceive('getJobId')->andReturn('1');
    $queueJob->shouldReceive('hasFailed')->andReturnFalse();
    $queueJob->shouldReceive('isReleased')->andReturnTrue();
    $queueJob->shouldReceive('isDeleted')->andReturnFalse();
    $queueJob->shouldReceive('isDeletedOrReleased')->andReturnTrue();

    return $queueJob;
}

it('stands aside while another worker holds the update', function (): void {
    telegramOk();
    $update = recordUpdate(startPayload(701400));

    // Somebody else got there first, in the real locks store.
    $held = telegramUpdateLock($update);

    expect($held->get())->toBeTrue();

    $job = new ProcessTelegramUpdateJob((int) $update->getKey());
    $job->setJob(releasableJob(5));
    app()->call([$job, 'handle']);

    // The processor never ran: no greeting, no customer, nothing marked done.
    Http::assertNothingSent();

    expect($update->fresh()->status)->toBe(TelegramUpdateStatus::Received)
        ->and($update->fresh()->processed_at)->toBeNull()
        ->and(User::query()->count())->toBe(0);

    // Released rather than dropped: once the holder is finished, the retry
    // does the work, exactly once.
    $held->release();
    runJob($update);

    expect($update->fresh()->status)->toBe(TelegramUpdateStatus::Processed)
        ->and(User::query()->count())->toBe(1)
        ->and(Http::recorded()->count())->toBe(1);
});

it('holds the update back when the locks store cannot coordinate at all', function (): void {
    telegramOk();
    $update = recordUpdate(startPayload(701500));

    // A cache that works and simply has no locks to give.
    Cache::extend('lockless', fn (): Repository => new Repository(new LocklessStore));
    config()->set('cache.stores.locks', ['driver' => 'lockless']);
    Cache::forgetDriver('locks');

    $job = new ProcessTelegramUpdateJob((int) $update->getKey());
    $job->setJob(releasableJob(5));
    app()->call([$job, 'handle']);

    // Uncoordinated processing is not the fallback. Nothing was sent, nobody
    // was created, and the update is still there for a properly configured
    // worker to pick up.
    Http::assertNothingSent();

    expect($update->fresh()->status)->toBe(TelegramUpdateStatus::Received)
        ->and($update->fresh()->processed_at)->toBeNull()
        ->and(User::query()->count())->toBe(0);
});

it('gives the lock back when it is finished, success or failure', function (): void {
    // One sequence for the whole test: a greeting that works, then one that
    // Telegram rejects. Declared once, because a second fake would be appended
    // to this one rather than replacing it.
    Http::fake(['api.telegram.test/*' => Http::sequence()
        ->push(['ok' => true, 'result' => ['message_id' => 1]])
        ->push(['ok' => false, 'error_code' => 400, 'description' => 'nope'])]);

    $update = recordUpdate(startPayload(701600));

    runJob($update);

    $afterSuccess = telegramUpdateLock($update);

    expect($afterSuccess->get())->toBeTrue();

    $afterSuccess->release();

    // And when the work threw rather than succeeded: the release is in a
    // finally, so a failing update does not stay locked for its whole TTL.
    $failing = recordUpdate(startPayload(701601, 5500999888));

    expect(fn () => runJob($failing))->toThrow(App\Telegram\Exceptions\TelegramRejected::class);

    $afterFailure = telegramUpdateLock($failing);

    expect($afterFailure->get())->toBeTrue();

    $afterFailure->release();
});
