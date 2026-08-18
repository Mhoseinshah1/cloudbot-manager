<?php

namespace App\Services\Telegram;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * HTTP client for the Telegram Bot API.
 *
 * All calls go through this client; no other code makes direct HTTP
 * requests to Telegram. Configurable base URL allows easy testing
 * with Http::fake().
 */
class TelegramApiClient
{
    public function sendMessage(int $chatId, string $text, array $options = []): ?array
    {
        return $this->call('sendMessage', array_merge([
            'chat_id' => $chatId,
            'text' => $text,
            'parse_mode' => 'HTML',
        ], $options));
    }

    public function editMessageText(int $chatId, int $messageId, string $text, array $options = []): ?array
    {
        return $this->call('editMessageText', array_merge([
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'text' => $text,
            'parse_mode' => 'HTML',
        ], $options));
    }

    public function answerCallbackQuery(string $callbackQueryId, array $options = []): ?array
    {
        return $this->call('answerCallbackQuery', array_merge([
            'callback_query_id' => $callbackQueryId,
        ], $options));
    }

    public function deleteMessage(int $chatId, int $messageId): ?array
    {
        return $this->call('deleteMessage', [
            'chat_id' => $chatId,
            'message_id' => $messageId,
        ]);
    }

    public function setWebhook(string $url, ?string $secretToken = null): ?array
    {
        $params = ['url' => $url];

        if ($secretToken !== null) {
            $params['secret_token'] = $secretToken;
        }

        return $this->call('setWebhook', $params);
    }

    public function deleteWebhook(): ?array
    {
        return $this->call('deleteWebhook');
    }

    public function getWebhookInfo(): ?array
    {
        return $this->call('getWebhookInfo');
    }

    /**
     * Call a Telegram Bot API method.
     *
     * @return array<string, mixed>|null The result field on success, null on failure
     */
    private function call(string $method, array $data = []): ?array
    {
        $token = config('telegram.bot_token', '');

        if ($token === '') {
            Log::warning('Telegram API call attempted without bot token', ['method' => $method]);

            return null;
        }

        $baseUrl = rtrim(config('telegram.api_base_url', 'https://api.telegram.org'), '/');

        try {
            $response = Http::timeout(10)
                ->post("{$token}/{$method}", $data);

            $body = $response->json();

            if (! is_array($body) || ($body['ok'] ?? false) !== true) {
                Log::error('Telegram API error', [
                    'method' => $method,
                    'description' => $body['description'] ?? 'Unknown error',
                    'error_code' => $body['error_code'] ?? null,
                ]);

                return null;
            }

            return $body['result'] ?? null;
        } catch (\Throwable $e) {
            Log::error('Telegram API exception', [
                'method' => $method,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }
}
