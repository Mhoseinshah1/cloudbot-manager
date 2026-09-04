<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Health\HealthChecker;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Unauthenticated readiness endpoint used by container and load-balancer probes.
 *
 * The body carries service states only. It must never expose credentials,
 * hostnames, versions, connection strings or exception text.
 */
final class HealthController extends Controller
{
    public function __invoke(HealthChecker $checker): JsonResponse
    {
        $report = $checker->check();

        return response()
            ->json(
                $report->toArray(),
                $report->isHealthy() ? Response::HTTP_OK : Response::HTTP_SERVICE_UNAVAILABLE,
            )
            ->header('Cache-Control', 'no-store');
    }
}
