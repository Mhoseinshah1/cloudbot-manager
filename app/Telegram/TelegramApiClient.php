<?php

declare(strict_types=1);

namespace App\Telegram;

use App\Telegram\Enums\TelegramMethod;
use App\Telegram\Exceptions\TelegramForbidden;
use App\Telegram\Exceptions\TelegramNotConfigured;
use App\Telegram\Exceptions\TelegramProtocolViolation;
use App\Telegram\Exceptions\TelegramRateLimited;
use App\Telegram\Exceptions\TelegramRejected;
use App\Telegram\Exceptions\TelegramTransportFailure;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Http\Client\Factory as HttpFactory;
use Throwable;

/**
 * Talks to Telegram, and does nothing else.
 *
 * Transport only. No order, no wallet, no provisioning logic reaches this
 * class, and it holds no opinion about what a message means — it turns a method
 * and a payload into an HTTP call, and turns Telegram's answer into either a
 * value or a typed exception.
 *
 * Two things it is careful about:
 *
 * The URL carries the bot token. Every URL this builds contains the whole
 * authority to act as this business, so no exception message, no log line and
 * no return value ever includes it — the method name is enough to say what
 * failed. The method itself comes from a closed enum rather than a string, so
 * nothing a customer sent can be appended to a URL that carries a credential.
 *
 * HTTP 200 is not success. Telegram wraps everything in an `ok` envelope, and a
 * proxy or a captive portal will happily answer 200 with a page that is not it.
 * A body that is not the documented shape fails closed rather than being read
 * optimistically.
 */
final readonly class TelegramApiClient
{
    public function __construct(
        private HttpFactory $http,
        private Config $config,
    ) {}

    /**
     * Send a message to a chat.
     *
     * @param  array<string, mixed>|null  $replyMarkup
     * @return array<string, mixed> The sent message, as Telegram describes it.
     */
    public function sendMessage(
        int $chatId,
        string $text,
        ?array $replyMarkup = null,
    ): array {
        $payload = ['chat_id' => $chatId, 'text' => $text];

        if ($replyMarkup !== null) {
            $payload['reply_markup'] = json_encode($replyMarkup, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        }

        return $this->expectObject(TelegramMethod::SendMessage, $payload, $chatId);
    }

    /**
     * @param  array<string, mixed>|null  $replyMarkup
     * @return array<string, mixed>|bool Telegram returns `true` for a message
     *                                   it cannot echo back.
     */
    public function editMessageText(
        int $chatId,
        int $messageId,
        string $text,
        ?array $replyMarkup = null,
    ): array|bool {
        $payload = ['chat_id' => $chatId, 'message_id' => $messageId, 'text' => $text];

        if ($replyMarkup !== null) {
            $payload['reply_markup'] = json_encode($replyMarkup, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        }

        $result = $this->call(TelegramMethod::EditMessageText, $payload, $chatId);

        if (is_array($result) || is_bool($result)) {
            return $result;
        }

        throw new TelegramProtocolViolation(TelegramMethod::EditMessageText, 'expected an object or a boolean');
    }

    /**
     * Stop the spinner on a customer's button.
     *
     * Answered promptly and separately from whatever the button does, because
     * Telegram leaves the client spinning until this returns.
     */
    public function answerCallbackQuery(string $callbackQueryId, ?string $text = null): bool
    {
        $payload = ['callback_query_id' => $callbackQueryId];

        if ($text !== null && $text !== '') {
            // Telegram's own limit. Truncated here rather than rejected: a long
            // notice is worth shortening, not worth failing the acknowledgement.
            $payload['text'] = mb_substr($text, 0, 200);
        }

        return $this->expectBoolean(TelegramMethod::AnswerCallbackQuery, $payload);
    }

    public function deleteMessage(int $chatId, int $messageId): bool
    {
        return $this->expectBoolean(
            TelegramMethod::DeleteMessage,
            ['chat_id' => $chatId, 'message_id' => $messageId],
            $chatId,
        );
    }

    /**
     * Point Telegram at this installation.
     *
     * The secret travels as `secret_token`, which is what Telegram then sends
     * back in a header on every delivery. It is never printed.
     */
    public function setWebhook(string $url, string $secret): bool
    {
        return $this->expectBoolean(TelegramMethod::SetWebhook, [
            'url' => $url,
            'secret_token' => $secret,
            // Only the updates this bot handles. Narrower than the default, so
            // Telegram does not deliver kinds nothing is written for.
            'allowed_updates' => json_encode(['message', 'callback_query'], JSON_THROW_ON_ERROR),
        ]);
    }

    public function deleteWebhook(): bool
    {
        return $this->expectBoolean(TelegramMethod::DeleteWebhook, []);
    }

    /**
     * @return array<string, mixed>
     */
    public function getWebhookInfo(): array
    {
        return $this->expectObject(TelegramMethod::GetWebhookInfo, []);
    }

    /**
     * Make one call and return whatever Telegram legitimately answered.
     *
     * Deliberately not typed `?array`. Telegram answers `true` for several of
     * these methods, and a signature that could not express that would force
     * every boolean success to be read as a failure.
     *
     * @param  array<string, mixed>  $payload
     * @param  int|null  $chatId  Carried so a 403 can name the chat that was refused.
     * @return array<string, mixed>|bool|int|string|float
     */
    public function call(TelegramMethod $method, array $payload = [], ?int $chatId = null): array|bool|int|string|float
    {
        $token = $this->botToken();

        try {
            $response = $this->http
                ->timeout((int) $this->config->get('telegram.timeout_seconds', 10))
                ->asForm()
                ->post($this->endpoint($token, $method), $payload);
        } catch (Throwable $exception) {
            // The message may quote the URL, and the URL carries the token. The
            // exception constructor scrubs it; this is why nothing here builds
            // its own string from the URL.
            throw new TelegramTransportFailure($method, $exception->getMessage());
        }

        /** @var mixed $body */
        $body = $response->json();

        if (! is_array($body) || ! array_key_exists('ok', $body)) {
            // A 200 with a body that is not the envelope. A proxy, a portal, or
            // an error page — never a delivered message.
            throw new TelegramProtocolViolation($method, 'the response was not a Telegram envelope');
        }

        if ($body['ok'] === true) {
            return $this->result($method, $body);
        }

        throw $this->failure($method, $body, $chatId);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function expectObject(TelegramMethod $method, array $payload, ?int $chatId = null): array
    {
        $result = $this->call($method, $payload, $chatId);

        if (! is_array($result)) {
            throw new TelegramProtocolViolation($method, 'expected an object result');
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function expectBoolean(TelegramMethod $method, array $payload, ?int $chatId = null): bool
    {
        $result = $this->call($method, $payload, $chatId);

        if (! is_bool($result)) {
            throw new TelegramProtocolViolation($method, 'expected a boolean result');
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>|bool|int|string|float
     */
    private function result(TelegramMethod $method, array $body): array|bool|int|string|float
    {
        $result = $body['result'] ?? null;

        if (is_array($result) || is_bool($result) || is_int($result) || is_string($result) || is_float($result)) {
            return $result;
        }

        throw new TelegramProtocolViolation($method, 'the successful result was not a usable value');
    }

    /**
     * Turn an `ok: false` envelope into the right typed exception.
     *
     * @param  array<string, mixed>  $body
     */
    private function failure(TelegramMethod $method, array $body, ?int $chatId): TelegramRateLimited|TelegramForbidden|TelegramRejected
    {
        $code = is_int($body['error_code'] ?? null) ? $body['error_code'] : null;
        $description = is_string($body['description'] ?? null) ? $body['description'] : 'no description';

        if ($code === 429) {
            return new TelegramRateLimited($method, $this->retryAfter($body), $description);
        }

        if ($code === 403) {
            return new TelegramForbidden($method, $chatId, $description);
        }

        return new TelegramRejected($method, $code, $description);
    }

    /**
     * How long Telegram asked us to wait, clamped.
     *
     * A missing or nonsensical value must not become either a hot loop or a job
     * parked for a week, so anything unusable becomes the configured fallback
     * and anything extreme is pulled into a sane band.
     *
     * @param  array<string, mixed>  $body
     */
    private function retryAfter(array $body): int
    {
        $parameters = $body['parameters'] ?? null;
        $requested = is_array($parameters) ? ($parameters['retry_after'] ?? null) : null;

        /** @var array<string, int> $bounds */
        $bounds = $this->config->get('telegram.retry_after', []);
        $minimum = (int) ($bounds['minimum_seconds'] ?? 1);
        $maximum = (int) ($bounds['maximum_seconds'] ?? 300);
        $fallback = (int) ($bounds['fallback_seconds'] ?? 5);

        if (! is_int($requested) && ! (is_string($requested) && preg_match('/^\d+$/', $requested) === 1)) {
            return max($minimum, min($maximum, $fallback));
        }

        return max($minimum, min($maximum, (int) $requested));
    }

    /**
     * The one place a URL is built, and the only thing interpolated into it is
     * a value from a closed enum.
     */
    private function endpoint(string $token, TelegramMethod $method): string
    {
        $base = rtrim((string) $this->config->get('telegram.api_base_url', 'https://api.telegram.org'), '/');

        return "{$base}/bot{$token}/{$method->value}";
    }

    private function botToken(): string
    {
        $token = $this->config->get('telegram.bot_token');

        if (! is_string($token) || trim($token) === '') {
            throw TelegramNotConfigured::missingBotToken();
        }

        return $token;
    }
}
