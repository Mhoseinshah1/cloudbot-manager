<?php

declare(strict_types=1);

use App\Telegram\Enums\TelegramMethod;
use App\Telegram\Exceptions\TelegramForbidden;
use App\Telegram\Exceptions\TelegramNotConfigured;
use App\Telegram\Exceptions\TelegramProtocolViolation;
use App\Telegram\Exceptions\TelegramRateLimited;
use App\Telegram\Exceptions\TelegramRejected;
use App\Telegram\Exceptions\TelegramTransportFailure;
use App\Telegram\TelegramApiClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * The transport boundary, exercised without ever reaching Telegram.
 *
 * Every call is faked. The two things worth proving here are that a Telegram
 * envelope is read correctly — including the successful answers that are not
 * objects — and that the bot token, which is in every URL this class builds,
 * never escapes into an exception or a log.
 */
beforeEach(function (): void {
    // Generated per run so the repository holds nothing credential-shaped, and
    // shaped like a real token so the scrubber's pattern is genuinely tested.
    $this->token = '77'.random_int(10_000_000, 99_999_999).':AA'.bin2hex(random_bytes(16));

    config()->set('telegram.bot_token', $this->token);
    config()->set('telegram.api_base_url', 'https://api.telegram.test');

    $this->telegram = app(TelegramApiClient::class);
});

function fakeTelegram(array $body, int $status = 200): void
{
    Http::fake(['api.telegram.test/*' => Http::response($body, $status)]);
}

it('calls the documented url shape', function (): void {
    fakeTelegram(['ok' => true, 'result' => ['message_id' => 1]]);

    $this->telegram->sendMessage(42, 'سلام');

    Http::assertSent(function (Request $request): bool {
        // /bot{token}/{method}, and the method comes from a closed enum.
        return $request->url() === "https://api.telegram.test/bot{$this->token}/sendMessage"
            && (int) $request['chat_id'] === 42
            && $request['text'] === 'سلام';
    });
});

it('accepts an object result', function (): void {
    fakeTelegram(['ok' => true, 'result' => ['message_id' => 9, 'text' => 'سلام']]);

    expect($this->telegram->sendMessage(42, 'سلام'))->toMatchArray(['message_id' => 9]);
});

it('accepts a boolean result', function (): void {
    // Telegram answers `true` for several methods. A client that could only
    // express an object would read every one of these as a failure.
    fakeTelegram(['ok' => true, 'result' => true]);

    expect($this->telegram->answerCallbackQuery('cb-1'))->toBeTrue()
        ->and($this->telegram->deleteMessage(42, 9))->toBeTrue()
        ->and($this->telegram->deleteWebhook())->toBeTrue();
});

it('accepts either shape from an edit', function (): void {
    // A sequence rather than two fakes: Telegram answers `true` when it cannot
    // echo the edited message back, and an object when it can.
    Http::fake(['api.telegram.test/*' => Http::sequence()
        ->push(['ok' => true, 'result' => true])
        ->push(['ok' => true, 'result' => ['message_id' => 9]])]);

    expect($this->telegram->editMessageText(42, 9, 'x'))->toBeTrue()
        ->and($this->telegram->editMessageText(42, 9, 'x'))->toMatchArray(['message_id' => 9]);
});

it('treats a rejection as a failure, not a result', function (): void {
    fakeTelegram(['ok' => false, 'error_code' => 400, 'description' => 'Bad Request: chat not found']);

    expect(fn () => $this->telegram->sendMessage(42, 'x'))
        ->toThrow(TelegramRejected::class);
});

it('refuses a 200 that is not a telegram envelope', function (): void {
    // A proxy, a captive portal, or an error page. Reading this as success
    // would report a message delivered that nobody received.
    Http::fake(['api.telegram.test/*' => Http::response('<html>gateway error</html>', 200)]);

    expect(fn () => $this->telegram->sendMessage(42, 'x'))
        ->toThrow(TelegramProtocolViolation::class);

    fakeTelegram(['result' => true]);

    expect(fn () => $this->telegram->sendMessage(42, 'x'))
        ->toThrow(TelegramProtocolViolation::class);
});

it('reports a network failure as transport, not rejection', function (): void {
    Http::fake(['api.telegram.test/*' => fn () => throw new ConnectionException('cURL error 28: timed out')]);

    expect(fn () => $this->telegram->sendMessage(42, 'x'))
        ->toThrow(TelegramTransportFailure::class);
});

it('carries the delay telegram asked for', function (): void {
    fakeTelegram([
        'ok' => false, 'error_code' => 429, 'description' => 'Too Many Requests',
        'parameters' => ['retry_after' => 7],
    ]);

    try {
        $this->telegram->sendMessage(42, 'x');
        $this->fail('Expected a rate limit.');
    } catch (TelegramRateLimited $limited) {
        expect($limited->retryAfterSeconds)->toBe(7)
            ->and($limited->method)->toBe(TelegramMethod::SendMessage)
            ->and($limited->isRetryable())->toBeTrue();
    }
});

it('clamps a delay that would be a hot loop or a week', function (string|int|null $requested, int $expected): void {
    $parameters = $requested === null ? [] : ['parameters' => ['retry_after' => $requested]];

    fakeTelegram(['ok' => false, 'error_code' => 429, 'description' => 'slow down', ...$parameters]);

    try {
        $this->telegram->sendMessage(42, 'x');
        $this->fail('Expected a rate limit.');
    } catch (TelegramRateLimited $limited) {
        expect($limited->retryAfterSeconds)->toBe($expected);
    }
})->with([
    'absent' => [null, 5],
    'nonsense' => ['soon', 5],
    'zero would be a hot loop' => [0, 1],
    'negative would be a hot loop' => [-30, 1],
    'a week is not a retry' => [604800, 300],
    'as asked' => [12, 12],
]);

it('names the chat telegram refused', function (): void {
    fakeTelegram(['ok' => false, 'error_code' => 403, 'description' => 'Forbidden: bot was blocked by the user']);

    try {
        $this->telegram->sendMessage(9988, 'x');
        $this->fail('Expected a refusal.');
    } catch (TelegramForbidden $forbidden) {
        // The chat it happened to, so the right account is marked — not
        // whichever account a username currently points at.
        expect($forbidden->chatId)->toBe(9988)
            ->and($forbidden->errorCode)->toBe(403)
            ->and($forbidden->isRetryable())->toBeFalse();
    }
});

it('keeps the bot token out of every exception', function (): void {
    $messages = [];

    // A rejection whose description quotes our own request back at us — which
    // is exactly how a token escapes in practice.
    fakeTelegram([
        'ok' => false, 'error_code' => 400,
        'description' => "Bad Request while calling https://api.telegram.test/bot{$this->token}/sendMessage",
    ]);

    try {
        $this->telegram->sendMessage(42, 'x');
    } catch (Throwable $e) {
        $messages[] = $e->getMessage();
    }

    // A transport error whose message contains the URL.
    Http::fake(['api.telegram.test/*' => fn () => throw new ConnectionException(
        "cURL error 7: failed to connect to https://api.telegram.test/bot{$this->token}/sendMessage",
    )]);

    try {
        $this->telegram->sendMessage(42, 'x');
    } catch (Throwable $e) {
        $messages[] = $e->getMessage();
    }

    expect($messages)->toHaveCount(2);

    foreach ($messages as $message) {
        expect($message)->not->toContain($this->token)
            ->and($message)->toContain('[redacted]');
    }
});

it('keeps the bot token out of the log', function (): void {
    $lines = [];
    Log::listen(function (object $entry) use (&$lines): void {
        $lines[] = $entry->message.' '.json_encode($entry->context, JSON_THROW_ON_ERROR);
    });

    fakeTelegram(['ok' => false, 'error_code' => 400, 'description' => "failed at /bot{$this->token}/sendMessage"]);

    try {
        $this->telegram->sendMessage(42, 'x');
    } catch (Throwable) {
        // The point is what was logged, not what was thrown.
    }

    // Whether or not anything was logged, nothing logged may carry the token.
    expect($lines)->each(fn ($line) => $line->not->toContain($this->token));

    $joined = implode(' ', $lines);

    expect($joined)->not->toContain($this->token);
});

it('refuses to act with no token configured', function (): void {
    config()->set('telegram.bot_token', null);
    Http::fake();

    expect(fn () => app(TelegramApiClient::class)->sendMessage(42, 'x'))
        ->toThrow(TelegramNotConfigured::class);

    // Nothing was sent: a missing credential stops the call, it does not
    // produce an anonymous one.
    Http::assertNothingSent();
});

it('sends the secret to telegram without printing it', function (): void {
    fakeTelegram(['ok' => true, 'result' => true]);

    $this->telegram->setWebhook('https://bot.example/telegram/webhook', 'the-shared-secret');

    Http::assertSent(function (Request $request): bool {
        return str_ends_with($request->url(), '/setWebhook')
            && $request['secret_token'] === 'the-shared-secret'
            && $request['url'] === 'https://bot.example/telegram/webhook';
    });
});
