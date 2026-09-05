<?php

declare(strict_types=1);

use App\Logging\RedactSecretsProcessor;
use Monolog\Level;
use Monolog\LogRecord;

/**
 * @param  array<array-key, mixed>  $context
 * @param  array<array-key, mixed>  $extra
 */
function record(string $message = 'test', array $context = [], array $extra = []): LogRecord
{
    return new LogRecord(
        datetime: new DateTimeImmutable,
        channel: 'testing',
        level: Level::Info,
        message: $message,
        context: $context,
        extra: $extra,
    );
}

it('redacts secret-bearing context keys', function (string $key): void {
    $result = (new RedactSecretsProcessor)(record(context: [$key => 'super-secret-value']));

    expect($result->context[$key])->toBe(RedactSecretsProcessor::REDACTED);
})->with([
    'password',
    'root_password',
    'DB_PASSWORD',
    'api_key',
    'apiKey',
    'provider_token',
    'telegram_bot_token',
    'webhook_secret',
    'Authorization',
    'credentials',
    'private_key',
]);

it('keeps values that are not secrets', function (): void {
    $result = (new RedactSecretsProcessor)(record(context: [
        'order_id' => 42,
        'provider_code' => 'hetzner',
        'server_id' => 'srv-1',
    ]));

    expect($result->context)->toBe([
        'order_id' => 42,
        'provider_code' => 'hetzner',
        'server_id' => 'srv-1',
    ]);
});

it('redacts secrets nested inside context', function (): void {
    $result = (new RedactSecretsProcessor)(record(context: [
        'provider' => ['code' => 'hetzner', 'credentials' => ['token' => 'abc123']],
    ]));

    expect($result->context['provider']['credentials'])->toBe(RedactSecretsProcessor::REDACTED)
        ->and($result->context['provider']['code'])->toBe('hetzner');
});

it('redacts bearer tokens inside message text', function (): void {
    $result = (new RedactSecretsProcessor)(record('Request failed with Authorization: Bearer abc123DEF456ghi'));

    expect($result->message)->not->toContain('abc123DEF456ghi')
        ->and($result->message)->toContain(RedactSecretsProcessor::REDACTED);
});

it('redacts telegram bot tokens inside message text', function (): void {
    $url = 'https://api.telegram.org/bot123456789:AAErjKLmnOPqrSTuvWXyz0123456789abcd/sendMessage';

    $result = (new RedactSecretsProcessor)(record("Calling {$url}"));

    expect($result->message)->not->toContain('AAErjKLmnOPqrSTuvWXyz0123456789abcd');
});

it('redacts an application key inside message text', function (): void {
    $result = (new RedactSecretsProcessor)(record('key is base64:'.str_repeat('A', 43).'='));

    expect($result->message)->not->toContain(str_repeat('A', 43));
});

it('redacts secrets in the extra array', function (): void {
    $result = (new RedactSecretsProcessor)(record(extra: ['token' => 'abc']));

    expect($result->extra['token'])->toBe(RedactSecretsProcessor::REDACTED);
});

it('leaves an ordinary message untouched', function (): void {
    $message = 'Provisioning started for order 42.';

    expect((new RedactSecretsProcessor)(record($message))->message)->toBe($message);
});

it('survives deeply nested context without recursing forever', function (): void {
    $deep = 'leaf';
    for ($i = 0; $i < 30; $i++) {
        $deep = ['nested' => $deep];
    }

    $result = (new RedactSecretsProcessor)(record(context: ['data' => $deep]));

    expect($result->context)->toBeArray();
});
