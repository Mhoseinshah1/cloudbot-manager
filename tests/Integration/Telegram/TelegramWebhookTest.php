<?php

declare(strict_types=1);

use App\Enums\TelegramUpdateStatus;
use App\Jobs\ProcessTelegramUpdateJob;
use App\Models\TelegramAccount;
use App\Models\TelegramUpdate;
use App\Models\User;
use App\Support\Queues;
use App\Telegram\Enums\TelegramAction;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;

/**
 * Where Telegram delivers, and everything that must be true before anything is
 * acted on.
 *
 * Three separate guarantees live here: only Telegram can post to it, browser
 * CSRF does not break it while remaining in force everywhere else, and the
 * request returns without doing any of the work.
 */
beforeEach(function (): void {
    $this->secret = 'secret-'.bin2hex(random_bytes(8));
    config()->set('telegram.webhook_secret', $this->secret);

    foreach (Queues::names() as $queue) {
        Queue::clear($queue);
    }

    // Nothing in this phase may reach Telegram from a web request.
    Http::preventStrayRequests();
    Http::fake(['*' => Http::response(['ok' => true, 'result' => true])]);
});

function telegramPayload(int $updateId = 500100, array $overrides = []): array
{
    return [
        'update_id' => $updateId,
        'message' => [
            'message_id' => 11,
            'from' => ['id' => 7700123456789, 'is_bot' => false, 'first_name' => 'علی', 'username' => 'ali'],
            'chat' => ['id' => 7700123456789, 'type' => 'private'],
            'text' => '/start',
            ...$overrides,
        ],
    ];
}

function deliver(array $payload, ?string $secret = null, bool $withHeader = true): Illuminate\Testing\TestResponse
{
    $headers = $withHeader
        ? ['X-Telegram-Bot-Api-Secret-Token' => $secret ?? test()->secret]
        : [];

    return test()->postJson('/telegram/webhook', $payload, $headers);
}

it('accepts a delivery carrying the shared secret', function (): void {
    deliver(telegramPayload())->assertOk()->assertJson(['ok' => true]);

    expect(TelegramUpdate::query()->count())->toBe(1)
        ->and(TelegramUpdate::query()->firstOrFail()->update_id)->toBe(500100);
});

it('refuses a delivery with the wrong secret', function (): void {
    deliver(telegramPayload(), 'not-the-secret')->assertForbidden();

    // Nothing recorded and nothing queued: an unauthenticated request must not
    // be able to fill the table or the queue.
    expect(TelegramUpdate::query()->count())->toBe(0)
        ->and(Queue::size(Queues::Telegram->value))->toBe(0);
});

it('refuses a delivery with no secret header at all', function (): void {
    deliver(telegramPayload(), withHeader: false)->assertForbidden();

    expect(TelegramUpdate::query()->count())->toBe(0)
        ->and(Queue::size(Queues::Telegram->value))->toBe(0);
});

it('fails closed when the server has no secret configured', function (): void {
    // The dangerous alternative is accepting anything when unconfigured, which
    // turns a misdeployment into an open endpoint.
    config()->set('telegram.webhook_secret', null);

    deliver(telegramPayload(), 'anything')->assertStatus(500);

    expect(TelegramUpdate::query()->count())->toBe(0)
        ->and(Queue::size(Queues::Telegram->value))->toBe(0);
});

it('keeps the webhook secret out of the response', function (): void {
    $response = deliver(telegramPayload(), 'wrong-secret');

    expect($response->getContent())->not->toContain($this->secret)
        ->and($response->getContent())->not->toContain('wrong-secret');
});

it('is not subject to browser csrf, while csrf still protects the web group', function (): void {
    // The exemption is structural, not a disabled middleware: the webhook is
    // registered outside the web group, exactly as /health is, so CSRF is never
    // weakened for the browser traffic it actually defends.
    //
    // Asserted against the middleware stacks rather than by posting without a
    // token, because Laravel bypasses CSRF entirely while testing — a 419 could
    // never be produced here, and a test that appeared to prove one would be
    // proving nothing.
    $csrf = Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class;

    $webGroup = app('router')->getMiddlewareGroups()['web'] ?? [];

    // Still in force for browser routes.
    expect($webGroup)->toContain($csrf);

    $webhook = collect(Route::getRoutes()->getRoutes())
        ->first(fn (Illuminate\Routing\Route $route): bool => $route->uri() === 'telegram/webhook');

    expect($webhook)->not->toBeNull()
        ->and($webhook->gatherMiddleware())->not->toContain($csrf)
        // And it genuinely is outside the group, rather than having the
        // middleware individually excluded.
        ->and($webhook->gatherMiddleware())->not->toContain('web');

    // A route that does join the group carries the protection.
    Route::middleware('web')->post('/_csrf-probe', fn () => response('reached'));

    $probe = collect(Route::getRoutes()->getRoutes())
        ->first(fn (Illuminate\Routing\Route $route): bool => $route->uri() === '_csrf-probe');

    // Carries the group, and the group carries the protection asserted above.
    expect($probe->gatherMiddleware())->toContain('web');

    // With the full middleware stack running, the webhook is still accepted.
    $this->withMiddleware()
        ->postJson('/telegram/webhook', telegramPayload(500199), [
            'X-Telegram-Bot-Api-Secret-Token' => $this->secret,
        ])
        ->assertOk();
});

it('queues the work instead of doing it', function (): void {
    Queue::fake();

    // Proven by what did not happen rather than by a mock: the transport and
    // the processor are both final, and the evidence they leave is better than
    // a spy anyway.
    //
    // Telegram gives a webhook a short window, and slow work here manufactures
    // the very duplicate deliveries it then has to deduplicate.
    deliver(telegramPayload())->assertOk();

    // No Telegram call was made. Stray requests are already prevented, so this
    // would have failed loudly; asserting it makes the intent explicit.
    Http::assertNothingSent();

    // And the processor did not run: identifying a customer is the first thing
    // it does, and nothing was created.
    expect(User::query()->count())->toBe(0)
        ->and(TelegramAccount::query()->count())->toBe(0)
        ->and(TelegramUpdate::query()->firstOrFail()->isPending())->toBeTrue();

    // The work exists, it is simply somewhere else.
    Queue::assertPushed(
        ProcessTelegramUpdateJob::class,
        static fn (ProcessTelegramUpdateJob $job): bool => $job->queue === Queues::Telegram->value,
    );
});

it('puts the work on the telegram queue and nowhere else', function (): void {
    deliver(telegramPayload())->assertOk();

    expect(Queue::size(Queues::Telegram->value))->toBe(1)
        ->and(Queue::size(Queues::Provisioning->value))->toBe(0)
        ->and(Queue::size(Queues::Notifications->value))->toBe(0)
        ->and(Queue::size(Queues::Default->value))->toBe(0);
});

it('records one row however many times telegram redelivers', function (): void {
    foreach (range(1, 4) as $ignored) {
        deliver(telegramPayload())->assertOk();
    }

    expect(TelegramUpdate::query()->count())->toBe(1);
});

it('does not queue more work for an update already processed', function (): void {
    deliver(telegramPayload())->assertOk();

    TelegramUpdate::query()->firstOrFail()->forceFill([
        'status' => TelegramUpdateStatus::Processed,
        'processed_at' => now(),
    ])->save();

    Queue::clear(Queues::Telegram->value);
    deliver(telegramPayload())->assertOk();

    expect(Queue::size(Queues::Telegram->value))->toBe(0);
});

it('requeues an update whose first dispatch never happened', function (): void {
    // The Phase 7 lesson, applied here: a row that says work is owed, with
    // nothing queued to do it, is stuck forever. Telegram's retry is the only
    // thing left that can repair it, so a duplicate must not simply answer 200.
    $payload = telegramPayload(500123);

    // Simulate a request that committed the row and then failed to enqueue.
    deliver($payload)->assertOk();
    Queue::clear(Queues::Telegram->value);

    expect(Queue::size(Queues::Telegram->value))->toBe(0)
        ->and(TelegramUpdate::query()->firstOrFail()->isPending())->toBeTrue();

    deliver($payload)->assertOk();

    expect(Queue::size(Queues::Telegram->value))->toBe(1)
        ->and(TelegramUpdate::query()->count())->toBe(1);
});

it('requeues an update whose handling failed', function (): void {
    deliver(telegramPayload())->assertOk();

    TelegramUpdate::query()->firstOrFail()->forceFill([
        'status' => TelegramUpdateStatus::Failed,
        'failure_reason' => 'telegram_api_error',
    ])->save();

    Queue::clear(Queues::Telegram->value);
    deliver(telegramPayload())->assertOk();

    expect(Queue::size(Queues::Telegram->value))->toBe(1)
        ->and(TelegramUpdate::query()->count())->toBe(1);
});

it('stores what the update meant, never what was typed', function (): void {
    $secret = 'hunter2-'.bin2hex(random_bytes(4));

    deliver(telegramPayload(500400, ['text' => "my password is {$secret} please help"]))->assertOk();

    $update = TelegramUpdate::query()->firstOrFail();
    $row = json_encode($update->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);

    expect($update->action)->toBe(TelegramAction::Unknown)
        // The text is not stored anywhere on the row.
        ->and($row)->not->toContain($secret)
        ->and($row)->not->toContain('my password is');
});

it('records an unrecognised update kind without choking on it', function (): void {
    deliver(['update_id' => 500500, 'poll_answer' => ['poll_id' => 'x']])->assertOk();

    $update = TelegramUpdate::query()->firstOrFail();

    expect($update->type->value)->toBe('other')
        ->and($update->action)->toBe(TelegramAction::Unknown)
        ->and($update->telegram_user_id)->toBeNull();
});

it('answers a payload with no update id without recording anything', function (): void {
    // Nothing to deduplicate on, so no safe way to handle it even once. A 200
    // stops Telegram retrying what can never be accepted.
    deliver(['message' => ['text' => '/start']])->assertOk();

    expect(TelegramUpdate::query()->count())->toBe(0)
        ->and(Queue::size(Queues::Telegram->value))->toBe(0);
});

it('round-trips telegram ids that do not fit in 32 bits', function (): void {
    deliver([
        'update_id' => 9_007_199_254_740_000,
        'message' => [
            'message_id' => 1,
            'from' => ['id' => 8_123_456_789_012, 'is_bot' => false],
            'chat' => ['id' => 8_123_456_789_013, 'type' => 'private'],
            'text' => '/start',
        ],
    ])->assertOk();

    $update = TelegramUpdate::query()->firstOrFail();

    expect($update->update_id)->toBe(9_007_199_254_740_000)
        ->and($update->telegram_user_id)->toBe(8_123_456_789_012)
        ->and($update->telegram_chat_id)->toBe(8_123_456_789_013);
});
