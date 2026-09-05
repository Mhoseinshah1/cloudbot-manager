<?php

declare(strict_types=1);

namespace App\Health;

/**
 * The outcome of a health check.
 *
 * Deliberately carries no diagnostic detail: this report is serialised to an
 * unauthenticated endpoint, so it must not leak hostnames, credentials,
 * versions or exception messages. Operators get the detail from logs.
 */
final readonly class HealthReport
{
    /**
     * @param  array<string, bool>  $services  Service name => reachable.
     */
    public function __construct(public array $services) {}

    public function isHealthy(): bool
    {
        return ! in_array(false, $this->services, true);
    }

    /**
     * @return array{status: string, services: array<string, string>}
     */
    public function toArray(): array
    {
        return [
            'status' => $this->isHealthy() ? 'ok' : 'degraded',
            'services' => array_map(
                static fn (bool $up): string => $up ? 'up' : 'down',
                $this->services,
            ),
        ];
    }

    /**
     * @return list<string>
     */
    public function failedServices(): array
    {
        return array_keys(array_filter($this->services, static fn (bool $up): bool => ! $up));
    }
}
