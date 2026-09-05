<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Health\HealthChecker;
use Illuminate\Console\Command;

/**
 * Health check for containers that serve no HTTP: PHP-FPM, the queue workers
 * and the scheduler.
 *
 * Shares HealthChecker with the HTTP endpoint so there is exactly one
 * definition of what "healthy" means.
 */
final class HealthCheckCommand extends Command
{
    protected $signature = 'app:health';

    protected $description = 'Verify that the application can reach PostgreSQL and Redis.';

    public function handle(HealthChecker $checker): int
    {
        $report = $checker->check();

        foreach ($report->toArray()['services'] as $service => $state) {
            $this->line(sprintf('%-10s %s', $service, $state));
        }

        if ($report->isHealthy()) {
            return self::SUCCESS;
        }

        $this->error('Unhealthy: '.implode(', ', $report->failedServices()));

        return self::FAILURE;
    }
}
