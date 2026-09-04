<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gives every request an id that appears on all of its log lines.
 *
 * Work in this system spans a web request, a queue worker and a provider call.
 * Without a shared id, reconstructing what happened to one order means guessing
 * from timestamps.
 *
 * An inbound id is accepted only if it looks like an id we generated; anything
 * else is replaced, so a caller cannot inject arbitrary text into our logs.
 */
final class AssignRequestId
{
    public const HEADER = 'X-Request-Id';

    private const MAX_LENGTH = 64;

    public function handle(Request $request, Closure $next): Response
    {
        $requestId = $this->resolveRequestId($request);

        $request->headers->set(self::HEADER, $requestId);
        Log::shareContext(['request_id' => $requestId]);

        /** @var Response $response */
        $response = $next($request);
        $response->headers->set(self::HEADER, $requestId);

        return $response;
    }

    private function resolveRequestId(Request $request): string
    {
        $supplied = $request->headers->get(self::HEADER);

        if (is_string($supplied)
            && $supplied !== ''
            && strlen($supplied) <= self::MAX_LENGTH
            && preg_match('/^[A-Za-z0-9._\-]+$/', $supplied) === 1
        ) {
            return $supplied;
        }

        return (string) Str::uuid();
    }
}
