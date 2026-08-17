<?php

use App\Exceptions\ProviderApiException;
use App\Exceptions\ProviderAuthenticationException;
use App\Exceptions\ProviderConflictException;
use App\Exceptions\ProviderNotFoundException;
use App\Exceptions\ProviderRateLimitException;
use App\Exceptions\ProviderValidationException;
use App\Integrations\Hetzner\HetznerApiClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Fixtures\HetznerApiFixtures as F;

function hetznerClient(array $options = []): HetznerApiClient
{
    return new HetznerApiClient(F::TOKEN, [
        'base_url' => F::BASE_URL,
        'retry_attempts' => 3,
        'retry_delay_ms' => 1,
        ...$options,
    ]);
}

it('sends the token as a bearer Authorization header and never leaks it', function () {
    Http::fake(['api.hetzner.test/v1/locations*' => Http::response(F::locationsResponse())]);

    hetznerClient()->get('/locations');

    Http::assertSent(fn (Request $request): bool => $request->hasHeader('Authorization', 'Bearer '.F::TOKEN));

    // The token must never appear in request URLs or bodies either.
    collect(Http::recorded())->each(function (array $pair) {
        expect($pair[0]->url())->not->toContain(F::TOKEN);
        expect($pair[0]->body())->not->toContain(F::TOKEN);
    });
});

it('follows pagination across multiple pages', function () {
    Http::fake([
        'api.hetzner.test/v1/server_types*' => function (Request $request) {
            $page = (int) ($request['page'] ?? 1);

            return Http::response(F::serverTypesResponse($page, 2));
        },
    ]);

    $types = hetznerClient()->getAll('/server_types', 'server_types', perPage: 2);

    expect($types)->toHaveCount(5);
    expect(array_column($types, 'name'))->toContain('cx22', 'cax31');
});

it('maps a 401 to ProviderAuthenticationException', function () {
    Http::fake(['api.hetzner.test/v1/locations*' => Http::response(F::error(401, 'unauthorized', 'unable to authorize you'), 401)]);

    expect(fn () => hetznerClient()->get('/locations'))
        ->toThrow(ProviderAuthenticationException::class);
});

it('maps a 403 to ProviderAuthenticationException', function () {
    Http::fake(['api.hetzner.test/v1/locations*' => Http::response(F::error(403, 'forbidden', 'insufficient permissions'), 403)]);

    expect(fn () => hetznerClient()->get('/locations'))
        ->toThrow(ProviderAuthenticationException::class);
});

it('maps a 404 to ProviderNotFoundException', function () {
    Http::fake(['api.hetzner.test/v1/servers/9999' => Http::response(F::error(404, 'not_found', 'resource not found'), 404)]);

    expect(fn () => hetznerClient()->get('/servers/9999'))
        ->toThrow(ProviderNotFoundException::class);
});

it('maps a 409 to ProviderConflictException', function () {
    Http::fake(['api.hetzner.test/v1/servers/1234/actions/poweron' => Http::response(F::error(409, 'conflict', 'action already in progress'), 409)]);

    expect(fn () => hetznerClient()->post('/servers/1234/actions/poweron'))
        ->toThrow(ProviderConflictException::class);
});

it('maps a 422 to ProviderValidationException with details', function () {
    Http::fake(['api.hetzner.test/v1/servers' => Http::response(F::error(422, 'invalid_input', 'validation failed', ['fields' => ['name' => ['must be unique']]]), 422)]);

    try {
        hetznerClient()->post('/servers', ['name' => 'dup']);
        $this->fail('Expected ProviderValidationException');
    } catch (ProviderValidationException $e) {
        expect($e->details)->toHaveKey('fields');
    }
});

it('maps a 429 to ProviderRateLimitException and parses Retry-After', function () {
    Http::fake(['api.hetzner.test/v1/locations*' => Http::response(F::error(429, 'rate_limit_exceeded', 'rate limit exceeded'), 429, ['Retry-After' => '5'])]);

    try {
        hetznerClient(['retry_attempts' => 1])->get('/locations');
        $this->fail('Expected ProviderRateLimitException');
    } catch (ProviderRateLimitException $e) {
        expect($e->retryAfterSeconds)->toBe(5);
    }
});

it('maps a 5xx to ProviderApiException', function () {
    Http::fake(['api.hetzner.test/v1/locations*' => Http::response(F::error(500, 'internal_error', 'something went wrong'), 500)]);

    expect(fn () => hetznerClient(['retry_attempts' => 1])->get('/locations'))
        ->toThrow(ProviderApiException::class);
});

it('retries GET requests on transient 429s and 5xx with bounded backoff', function () {
    Http::fake([
        'api.hetzner.test/v1/locations*' => Http::sequence()
            ->push(F::error(429, 'rate_limit_exceeded', 'slow down'), 429)
            ->push(F::error(500, 'internal_error', 'oops'), 500)
            ->push(F::locationsResponse()),
    ]);

    $data = hetznerClient()->get('/locations');

    expect($data)->toHaveKey('locations');
    expect(collect(Http::recorded()))->toHaveCount(3);
});

it('never retries POST requests', function () {
    Http::fake([
        'api.hetzner.test/v1/servers' => Http::sequence()
            ->push(F::error(429, 'rate_limit_exceeded', 'slow down'), 429)
            ->push(F::createdServerResponse()),
    ]);

    expect(fn () => hetznerClient()->post('/servers', ['name' => 'x']))
        ->toThrow(ProviderRateLimitException::class);

    // Only the first attempt was sent — the create was never blindly retried.
    expect(collect(Http::recorded()))->toHaveCount(1);
});

it('never retries DELETE requests', function () {
    Http::fake(['api.hetzner.test/v1/servers/1234' => Http::response(F::error(500, 'internal_error', 'oops'), 500)]);

    expect(fn () => hetznerClient()->delete('/servers/1234'))
        ->toThrow(ProviderApiException::class);

    // Only the first attempt was sent — the delete was never blindly retried.
    expect(collect(Http::recorded()))->toHaveCount(1);
});

it('throws a sanitized ProviderApiException on connection failure after bounded retries', function () {
    Http::fake([
        'api.hetzner.test/v1/locations*' => fn () => throw new ConnectionException('cURL error 28: Operation timed out'),
    ]);

    try {
        hetznerClient()->get('/locations');
        $this->fail('Expected ProviderApiException');
    } catch (ProviderApiException $e) {
        expect($e->getMessage())->toContain('connection failed');
        expect($e->getMessage())->not->toContain(F::TOKEN);
    }
});

it('never includes the API token in any exception message', function () {
    foreach (F::errorFixtures() as [$status, $body]) {
        Http::fake(['api.hetzner.test/v1/whatever' => Http::response($body, $status)]);

        try {
            hetznerClient(['retry_attempts' => 1])->get('/whatever');
        } catch (Throwable $e) {
            expect($e->getMessage())->not->toContain(F::TOKEN);
        }
    }
});

it('rejects an empty token at construction', function () {
    expect(fn () => new HetznerApiClient(''))
        ->toThrow(InvalidArgumentException::class);
});
