<?php

declare(strict_types=1);

namespace App\Cloud\Hetzner;

use App\Cloud\Exceptions\ProviderException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;

/**
 * Every HTTP call to Hetzner goes through here, and nothing else does.
 *
 * Built on Laravel's HTTP client rather than a Guzzle instance of its own, so
 * `Http::fake()` can stand in for the whole provider without a real token and
 * without a network.
 *
 * The distinction this class exists to enforce is read versus write.
 *
 * A read may be retried: it changes nothing, and a second GET that succeeds is
 * strictly better than an exception. A write may not. Exactly one create, one
 * delete, one reboot leaves this process per call, whatever happens — because a
 * retried POST that the first attempt actually delivered is a second VPS the
 * customer did not buy, or a second reboot of a machine they are using. When a
 * write's outcome is unknown, the honest answer is `UncertainResult` and the
 * reconciler's job to resolve it against the provisioning token.
 */
final readonly class HetznerHttpClient
{
    /** Hetzner's maximum, and the fewest round trips per sync. */
    private const PER_PAGE = 50;

    /**
     * A ceiling on pagination.
     *
     * Not expected to be reached. It is here because a provider bug that made
     * `next_page` cycle would otherwise be an infinite loop inside a queue
     * worker holding a lock.
     */
    private const MAX_PAGES = 200;

    /** Bounded and small. Retries are for a blip, not for an outage. */
    private const READ_ATTEMPTS = 3;

    public function __construct(
        private HttpFactory $http,
        private HetznerCredentials $credentials,
        private HetznerErrorMapper $errors,
    ) {}

    /**
     * A GET that may be safely repeated.
     *
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     *
     * @throws ProviderException
     */
    public function get(string $path, array $query = [], string $operation = 'get'): array
    {
        return $this->send('GET', $path, $query, null, $operation, mutating: false);
    }

    /**
     * A single write. Never retried, whatever the failure.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     *
     * @throws ProviderException
     */
    public function post(string $path, array $payload = [], string $operation = 'post'): array
    {
        return $this->send('POST', $path, [], $payload, $operation, mutating: true);
    }

    /**
     * A single delete. Never retried.
     *
     * @return array<string, mixed>
     *
     * @throws ProviderException
     */
    public function delete(string $path, string $operation = 'delete'): array
    {
        return $this->send('DELETE', $path, [], null, $operation, mutating: true);
    }

    /**
     * Walk a paginated collection to the end.
     *
     * Silently returning the first page is the failure this exists to prevent:
     * a partial `listServers()` makes the inventory reconciler mark every
     * server past page one as missing, which stops real subscriptions.
     *
     * @param  array<string, mixed>  $query
     * @return list<array<string, mixed>>
     *
     * @throws ProviderException
     */
    public function paginate(string $path, string $collection, array $query = [], string $operation = 'list'): array
    {
        $items = [];
        $page = 1;
        $seen = [];

        for ($visited = 0; $visited < self::MAX_PAGES; $visited++) {
            $body = $this->get($path, [...$query, 'page' => $page, 'per_page' => self::PER_PAGE], $operation);

            $rows = $body[$collection] ?? null;

            if (! is_array($rows)) {
                throw $this->errors->malformed($operation, 'the '.$collection.' collection is missing.');
            }

            foreach ($rows as $row) {
                if (is_array($row)) {
                    $items[] = $row;
                }
            }

            $next = self::nextPage($body);

            if ($next === null) {
                return $items;
            }

            if (isset($seen[$next]) || $next <= $page) {
                // A cursor that repeats or goes backwards is a loop. Stopping
                // with what we have would be worse than saying so: a short list
                // read as complete is what marks live servers missing.
                throw $this->errors->malformed($operation, 'pagination did not advance.');
            }

            $seen[$page] = true;
            $page = $next;
        }

        throw $this->errors->malformed($operation, 'pagination exceeded its page bound.');
    }

    /**
     * @param  array<string, mixed>  $query
     * @param  array<string, mixed>|null  $payload
     * @return array<string, mixed>
     *
     * @throws ProviderException
     */
    private function send(
        string $method,
        string $path,
        array $query,
        ?array $payload,
        string $operation,
        bool $mutating,
    ): array {
        $attempts = $mutating ? 1 : self::READ_ATTEMPTS;
        $last = null;

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            try {
                $response = $this->request()->send($method, $this->url($path), array_filter([
                    'query' => $query === [] ? null : $query,
                    'json' => $payload,
                ], static fn (mixed $value): bool => $value !== null));
            } catch (ConnectionException $exception) {
                // Laravel folds connect failures and read timeouts into one
                // exception type, and the difference matters for a write: a
                // connection that never opened delivered nothing, while a
                // request that went out and timed out may have been executed.
                // Without being able to tell them apart, a write must assume
                // the dangerous one.
                $last = $mutating
                    ? $this->errors->timedOut($operation, mutating: true)
                    : $this->errors->connectionFailed($operation);

                if ($mutating) {
                    throw $last;
                }

                continue;
            }

            if ($response->successful()) {
                return self::decode($response, $operation, $this->errors);
            }

            $failure = $this->errors->fromResponse(
                $response->status(),
                self::decodeQuietly($response),
                $operation,
            );

            // A write's answer is final by construction: one request, one
            // outcome, and the layer above decides what to do about it.
            if ($mutating || ! self::worthRetrying($response->status())) {
                throw $failure;
            }

            $last = $failure;
        }

        throw $last ?? $this->errors->malformed($operation, 'no response was obtained.');
    }

    /**
     * Whether repeating this read could plausibly answer differently.
     *
     * A rate limit is deliberately excluded. Sleeping out Hetzner's window
     * inside a queue worker holds a lock and a process for as long as the
     * provider says; the durable retry barrier already in the system is the
     * right place for that wait, so the category is returned and the caller
     * comes back later.
     */
    private static function worthRetrying(int $status): bool
    {
        return $status >= 500;
    }

    private function request(): PendingRequest
    {
        $settings = $this->credentials->settings();

        return $this->http
            // The token is set as a bearer here and nowhere else. It is never
            // interpolated into a URL, a query string or a log line.
            ->withToken($this->credentials->token())
            ->acceptJson()
            ->asJson()
            ->connectTimeout($settings->connectTimeoutSeconds())
            ->timeout($settings->timeoutSeconds());
    }

    private function url(string $path): string
    {
        return $this->credentials->settings()->baseUrl.'/'.ltrim($path, '/');
    }

    /**
     * @return array<string, mixed>
     *
     * @throws ProviderException
     */
    private static function decode(Response $response, string $operation, HetznerErrorMapper $errors): array
    {
        $body = $response->json();

        if (! is_array($body)) {
            // A 204 from a delete is legitimate and carries nothing.
            if ($response->status() === 204 || trim($response->body()) === '') {
                return [];
            }

            throw $errors->malformed($operation, 'the response body was not JSON.');
        }

        /** @var array<string, mixed> $body */
        return $body;
    }

    /**
     * An error body, if it can be read. Never throws: the status already told
     * us this failed, and a second failure about the shape of the explanation
     * would replace a useful category with a useless one.
     *
     * @return array<string, mixed>
     */
    private static function decodeQuietly(Response $response): array
    {
        $body = $response->json();

        return is_array($body) ? $body : [];
    }

    /** @param array<string, mixed> $body */
    private static function nextPage(array $body): ?int
    {
        $meta = $body['meta'] ?? null;
        $pagination = is_array($meta) ? ($meta['pagination'] ?? null) : null;
        $next = is_array($pagination) ? ($pagination['next_page'] ?? null) : null;

        return is_int($next) && $next > 0 ? $next : null;
    }
}
