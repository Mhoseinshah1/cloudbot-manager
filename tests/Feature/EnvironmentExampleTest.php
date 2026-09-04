<?php

declare(strict_types=1);

function envExample(): string
{
    return (string) file_get_contents(base_path('.env.example'));
}

it('documents every variable this phase implements', function (string $variable): void {
    expect(envExample())->toContain($variable.'=');
})->with([
    'APP_NAME', 'APP_ENV', 'APP_KEY', 'APP_DEBUG', 'APP_URL', 'APP_BIND_IP', 'APP_PORT',
    'DB_CONNECTION', 'DB_HOST', 'DB_PORT', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD',
    'REDIS_HOST', 'REDIS_PORT', 'REDIS_PASSWORD',
    'REDIS_CACHE_DB', 'REDIS_QUEUE_DB', 'REDIS_STATE_DB', 'REDIS_LOCK_DB',
    'CACHE_STORE', 'SESSION_DRIVER', 'QUEUE_CONNECTION',
    'PROVIDER_OPERATION_TIMEOUT_SECONDS', 'PROVISIONING_LOCK_TTL_SECONDS',
    'TELEGRAM_BOT_TOKEN', 'TELEGRAM_WEBHOOK_SECRET', 'TELEGRAM_API_BASE_URL',
    'TELEGRAM_WEBHOOK_URL', 'TELEGRAM_STATE_TTL_SECONDS',
]);

it('ships no variable for a phase that has not been built', function (string $variable): void {
    // The example file is a contract about what exists. Listing a Telegram or
    // provider setting before the code reads it invites operators to configure
    // something that silently does nothing.
    expect(envExample())->not->toContain($variable);
})->with([
    'HETZNER',
    'FX_MAX_AGE_MINUTES',
    'ZARINPAL',
    'APP_CURRENCY',
]);

it('ships no default database password', function (): void {
    // A shipped default becomes a known production credential.
    expect(envExample())->toContain("DB_PASSWORD=\n")
        ->and(envExample())->not->toContain('DB_PASSWORD=secret');
});

it('ships no application key', function (): void {
    expect(envExample())->toContain("APP_KEY=\n")
        ->and(envExample())->not->toContain('APP_KEY=base64:');
});

it('binds the application to localhost by default', function (): void {
    expect(envExample())->toContain('APP_BIND_IP=127.0.0.1');
});

it('gives each redis concern a different database', function (): void {
    preg_match_all('/^REDIS_(?:CACHE|QUEUE|STATE|LOCK)_DB=(\d+)$/m', envExample(), $matches);

    expect($matches[1])->toHaveCount(4)
        ->and(array_unique($matches[1]))->toHaveCount(4);
});

it('keeps the ci environment free of real secrets', function (): void {
    $ci = (string) file_get_contents(base_path('.env.ci'));

    expect($ci)->toContain("APP_KEY=\n")
        ->and($ci)->not->toContain('APP_KEY=base64:');
});
