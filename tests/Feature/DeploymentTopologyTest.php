<?php

declare(strict_types=1);

use Symfony\Component\Yaml\Yaml;

/**
 * Guards the deployment invariants that have historically caused real
 * incidents: a publicly exposed database, a publicly bound application port,
 * a known default password, or a worker topology that lets slow provisioning
 * block the bot.
 *
 * These are static checks against the committed Compose file. They do not need
 * a Docker daemon, so they run everywhere the suite runs.
 */
function compose(): array
{
    return Yaml::parseFile(base_path('compose.yaml'));
}

it('defines exactly the release 1.0 services', function (): void {
    expect(array_keys(compose()['services']))
        ->toEqualCanonicalizing([
            'app',
            'nginx',
            'postgres',
            'redis',
            'telegram-worker',
            'provisioning-worker',
            'notification-worker',
            'scheduler',
        ]);
});

it('defines no billing worker', function (): void {
    // Hourly billing is Release 1.1. Shipping its worker early would run a
    // process with nothing to do and imply the feature exists.
    expect(compose()['services'])->not->toHaveKey('billing-worker');
});

it('publishes no port for postgres or redis', function (string $service): void {
    // Neither may be reachable from the host network, let alone the internet.
    expect(compose()['services'][$service])->not->toHaveKey('ports');
})->with(['postgres', 'redis']);

it('publishes only nginx, and binds it to localhost by default', function (): void {
    $services = compose()['services'];

    $publishing = array_keys(array_filter(
        $services,
        static fn (array $service): bool => isset($service['ports']),
    ));

    expect($publishing)->toBe(['nginx'])
        ->and($services['nginx']['ports'])->toBe(['${APP_BIND_IP:-127.0.0.1}:${APP_PORT:-8080}:80']);
});

it('never falls back to a default database password', function (): void {
    $environment = compose()['services']['postgres']['environment'];

    // ':?' makes Compose refuse to start without an explicit value, instead of
    // silently creating a cluster with a password an attacker could guess.
    expect($environment['POSTGRES_PASSWORD'])->toStartWith('${DB_PASSWORD:?');
});

it('contains no literal secrets', function (): void {
    $raw = file_get_contents(base_path('compose.yaml'));

    foreach (['password: secret', 'POSTGRES_PASSWORD: secret', 'base64:'] as $literal) {
        expect($raw)->not->toContain($literal);
    }
});

it('drains interactive and provisioning work with separate workers', function (): void {
    $services = compose()['services'];

    $queueOf = static function (array $service): string {
        foreach ($service['command'] as $argument) {
            if (str_starts_with((string) $argument, '--queue=')) {
                return substr((string) $argument, strlen('--queue='));
            }
        }

        return '';
    };

    expect($queueOf($services['telegram-worker']))->toBe('telegram,default')
        ->and($queueOf($services['provisioning-worker']))->toBe('provisioning')
        ->and($queueOf($services['notification-worker']))->toBe('notifications');
});

it('gives provisioning a longer timeout than interactive work', function (): void {
    $timeoutOf = static function (array $service): int {
        foreach ($service['command'] as $argument) {
            if (str_starts_with((string) $argument, '--timeout=')) {
                return (int) substr((string) $argument, strlen('--timeout='));
            }
        }

        return 0;
    };

    $services = compose()['services'];

    expect($timeoutOf($services['provisioning-worker']))
        ->toBeGreaterThan($timeoutOf($services['telegram-worker']));
});

it('restarts every service unless explicitly stopped', function (): void {
    foreach (compose()['services'] as $name => $service) {
        expect($service['restart'])->toBe('unless-stopped', "service {$name}");
    }
});

it('health checks every service', function (): void {
    // "running" is not "working": a container whose process is alive but whose
    // dependencies are gone must be reported unhealthy.
    $missing = array_keys(array_filter(
        compose()['services'],
        static fn (array $service): bool => ! isset($service['healthcheck']),
    ));

    expect($missing)->toBe([]);
});

it('waits for healthy infrastructure before starting dependents', function (): void {
    foreach (['app', 'telegram-worker', 'provisioning-worker', 'notification-worker', 'scheduler'] as $name) {
        $dependsOn = compose()['services'][$name]['depends_on'];

        expect($dependsOn['postgres']['condition'])->toBe('service_healthy', "service {$name}")
            ->and($dependsOn['redis']['condition'])->toBe('service_healthy', "service {$name}");
    }
});

it('caps container logs so they cannot fill the disk', function (): void {
    foreach (compose()['services'] as $name => $service) {
        expect($service['logging']['driver'])->toBe('json-file', "service {$name}")
            ->and($service['logging']['options']['max-size'])->toBe('10m', "service {$name}")
            ->and($service['logging']['options']['max-file'])->toBe('3', "service {$name}");
    }
});

it('limits memory for every service', function (): void {
    // One runaway worker must not be able to OOM the whole host.
    $missing = array_keys(array_filter(
        compose()['services'],
        static fn (array $service): bool => ! isset($service['mem_limit']),
    ));

    expect($missing)->toBe([]);
});

it('keeps redis from evicting queued jobs, state or locks', function (): void {
    $command = implode(' ', (array) compose()['services']['redis']['command']);

    expect($command)->toContain('noeviction')
        ->and($command)->toContain('appendonly yes');
});

it('publishes database and redis ports only in the development overlay', function (): void {
    $dev = Yaml::parseFile(base_path('compose.dev.yaml'));

    foreach (['postgres', 'redis'] as $service) {
        foreach ($dev['services'][$service]['ports'] as $mapping) {
            expect($mapping)->toStartWith('127.0.0.1:');
        }
    }
});
