<?php

namespace App\Integrations\Hetzner;

use App\Exceptions\ProviderApiException;
use App\Exceptions\ProviderAuthenticationException;
use App\Exceptions\ProviderConflictException;
use App\Exceptions\ProviderException;
use App\Exceptions\ProviderNotFoundException;
use App\Exceptions\ProviderRateLimitException;
use App\Exceptions\ProviderResourceUnavailableException;
use App\Exceptions\ProviderValidationException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;

/**
 * Thin HTTP client for the Hetzner Cloud API v1.
 *
 * - Token is sent via the Authorization header and is NEVER included in
 *   exceptions or logs.
 * - Only safe/transient failures (429, 5xx, connection errors on GET) are
 *   retried with bounded exponential backoff. POST /servers and DELETE are
 *   never retried automatically — idempotency is enforced by the caller.
 * - Provider errors are normalized into application exceptions.
 */
class HetznerApiClient
{
    private string $baseUrl;

    private int $timeout;

    private int $connectTimeout;

    private int $retryAttempts;

    private int $retryDelayMs;

    private int $actionTimeout;

    private int $actionPollingIntervalMs;

    public function __construct(
        private string $token,
        private array $options = [],
    ) {
        $this->baseUrl = rtrim((string) ($options['base_url'] ?? config('services.hetzner.base_url', 'https://api.hetzner.cloud/v1')), '/');
        $this->timeout = (int) ($options['timeout'] ?? config('services.hetzner.timeout', 30));
        $this->connectTimeout = (int) ($options['connect_timeout'] ?? config('services.hetzner.connect_timeout', 5));
        $this->retryAttempts = max(1, (int) ($options['retry_attempts'] ?? 3));
        $this->retryDelayMs = (int) ($options['retry_delay_ms'] ?? 250);
        $this->actionTimeout = (int) ($options['action_timeout'] ?? config('services.hetzner.action_timeout', 300));
        $this->actionPollingIntervalMs = max(
            100,
            (int) ($options['action_polling_interval_ms'] ?? config('services.hetzner.action_polling_interval_ms', 2000))
        );

        if ($this->token === '') {
            throw new InvalidArgumentException('A Hetzner API token is required.');
        }
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    public function get(string $path, array $query = []): array
    {
        return $this->request('GET', $path, $query, null, retryable: true);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function post(string $path, array $payload = []): array
    {
        return $this->request('POST', $path, [], $payload, retryable: false);
    }

    /**
     * @return array<string, mixed>
     */
    public function delete(string $path): array
    {
        return $this->request('DELETE', $path, [], null, retryable: false);
    }

    /**
     * Fetches a single asynchronous action by id.
     *
     * @return array<string, mixed>
     */
    public function getAction(int $actionId): array
    {
        return $this->get("/actions/{$actionId}");
    }

    /**
     * Polls an action until it reaches success/error, the configured
     * timeout elapses, or the polling budget is exhausted.
     *
     * Bounded and non-aggressive: polls at most every $pollingIntervalMs
     * and never past the deadline. On timeout a sanitized ProviderApiException
     * is thrown — the caller must NOT treat that as permission to re-create
     * resources (idempotency is enforced elsewhere).
     *
     * @return array<string, mixed>
     */
    public function waitForAction(int $actionId, ?int $timeoutSeconds = null, ?int $pollingIntervalMs = null): array
    {
        $timeout = max(1, $timeoutSeconds ?? $this->actionTimeout);
        $intervalMs = max(100, $pollingIntervalMs ?? $this->actionPollingIntervalMs);
        $deadline = microtime(true) + $timeout;

        do {
            $action = $this->getAction($actionId);

            if (($action['status'] ?? 'running') !== 'running') {
                return $action;
            }

            $remainingMs = (int) (($deadline - microtime(true)) * 1_000_000);
            if ($remainingMs <= 0) {
                break;
            }

            usleep(min($intervalMs * 1000, $remainingMs));
        } while (microtime(true) < $deadline);

        throw new ProviderApiException(
            "Hetzner action #{$actionId} did not finish within {$timeout}s."
        );
    }

    /**
     * Follows pagination across list endpoints.
     *
     * @param  array<string, mixed>  $query
     * @return array<int, array<string, mixed>>
     */
    public function getAll(string $path, string $itemsKey, array $query = [], int $perPage = 50): array
    {
        $items = [];
        $page = 1;

        do {
            $data = $this->get($path, [...$query, 'page' => $page, 'per_page' => $perPage]);
            $items = [...$items, ...($data[$itemsKey] ?? [])];

            $pagination = $data['meta']['pagination'] ?? [];
            $lastPage = (int) ($pagination['last_page'] ?? $page);
            $page = (int) ($pagination['next_page'] ?? 0);
        } while ($page > 0 && $page <= $lastPage);

        return $items;
    }

    /**
     * @param  array<string, mixed>  $query
     * @param  array<string, mixed>|null  $payload
     * @return array<string, mixed>
     */
    private function request(string $method, string $path, array $query = [], ?array $payload = null, bool $retryable = false): array
    {
        $attempts = $retryable ? $this->retryAttempts : 1;

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            try {
                $response = $this->send($method, $path, $query, $payload);
            } catch (ConnectionException $e) {
                if ($retryable && $attempt < $attempts) {
                    $this->backoff($attempt);

                    continue;
                }

                throw new ProviderApiException('Hetzner API connection failed: '.$e->getMessage());
            }

            if ($response->successful()) {
                return $response->json() ?? [];
            }

            $exception = $this->mapError($response, $path);

            // Safe/transient failures may be retried on GET requests only.
            if ($retryable && $attempt < $attempts && $exception instanceof ProviderRateLimitException) {
                $this->backoff($attempt, $exception->retryAfterSeconds);

                continue;
            }
            if ($retryable && $attempt < $attempts && $exception instanceof ProviderApiException) {
                $this->backoff($attempt);

                continue;
            }

            throw $exception;
        }

        throw new ProviderApiException("Hetzner API request to {$path} failed after retries.");
    }

    /**
     * @param  array<string, mixed>  $query
     * @param  array<string, mixed>|null  $payload
     */
    private function send(string $method, string $path, array $query, ?array $payload): Response
    {
        $http = Http::baseUrl($this->baseUrl)
            ->withToken($this->token)
            ->withHeaders(['Accept' => 'application/json'])
            ->asJson()
            ->timeout($this->timeout)
            ->connectTimeout($this->connectTimeout);

        return match ($method) {
            'GET' => $http->get($path, $query),
            'POST' => $http->post($path, $payload ?? []),
            'DELETE' => $http->delete($path),
            default => throw new InvalidArgumentException("Unsupported HTTP method [{$method}]."),
        };
    }

    private function mapError(Response $response, string $path): ProviderException
    {
        $status = $response->status();

        // Hetzner error bodies: {"error": {"code": "...", "message": "...", "details": {...}}}
        $body = $response->json();
        $error = $body['error'] ?? [];
        $code = (string) ($error['code'] ?? 'unknown');
        $message = (string) ($error['message'] ?? 'Unknown Hetzner API error');

        // Never include tokens/headers; only the sanitized provider message.
        $sanitized = "Hetzner API {$status} {$path}: {$message} ({$code})";

        return match (true) {
            $status === 401 || $status === 403 => new ProviderAuthenticationException($sanitized),
            $status === 404 => new ProviderNotFoundException($sanitized),
            $status === 409 => new ProviderConflictException($sanitized),
            $status === 422 => (new ProviderValidationException($sanitized))
                ->withDetails($error['details'] ?? []),
            $status === 429 => new ProviderRateLimitException(
                $sanitized,
                retryAfterSeconds: $this->retryAfterSeconds($response),
            ),
            $status >= 500 => new ProviderApiException($sanitized),
            default => new ProviderResourceUnavailableException($sanitized),
        };
    }

    private function retryAfterSeconds(Response $response): ?int
    {
        $retryAfter = $response->header('Retry-After');

        if (ctype_digit($retryAfter)) {
            return (int) $retryAfter;
        }

        $reset = $response->header('ratelimit-reset');

        if (ctype_digit($reset)) {
            return max(0, (int) $reset - time());
        }

        return null;
    }

    private function backoff(int $attempt, ?int $retryAfterSeconds = null): void
    {
        if ($retryAfterSeconds !== null) {
            usleep(min($retryAfterSeconds, 30) * 1_000_000);

            return;
        }

        $delayMs = $this->retryDelayMs * (2 ** ($attempt - 1));
        usleep($delayMs * 1000);
    }
}
