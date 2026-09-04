<?php

declare(strict_types=1);

use App\Logging\RedactSecretsProcessor;
use Illuminate\Support\Facades\Log;

/**
 * The unit tests prove the processor redacts. These prove it is actually
 * wired into the loggers the application uses: a correct processor that never
 * runs would be worse than none, because it would look safe.
 */
beforeEach(function (): void {
    $this->logFile = storage_path('logs/redaction-test.log');

    config()->set('logging.channels.redaction_test', [
        'driver' => 'single',
        'path' => $this->logFile,
        'level' => 'debug',
        'tap' => [App\Logging\RedactSecrets::class],
    ]);

    @unlink($this->logFile);
});

afterEach(function (): void {
    @unlink($this->logFile);
});

it('redacts secrets written through a configured channel', function (): void {
    Log::channel('redaction_test')->info('Provider call failed.', [
        'provider_code' => 'hetzner',
        'api_token' => 'live-token-value-9876',
        'root_password' => 'correct-horse-battery',
    ]);

    $written = (string) file_get_contents($this->logFile);

    expect($written)
        ->not->toContain('live-token-value-9876')
        ->not->toContain('correct-horse-battery')
        ->toContain(RedactSecretsProcessor::REDACTED)
        // Non-secret diagnostic context must survive, or logs become useless.
        ->toContain('hetzner');
});

it('redacts a bot token that appears in the message itself', function (): void {
    Log::channel('redaction_test')->error(
        'GET https://api.telegram.org/bot987654321:AAHkLmnOPqrSTuvWXyz0123456789abcdef/getMe failed'
    );

    expect((string) file_get_contents($this->logFile))
        ->not->toContain('AAHkLmnOPqrSTuvWXyz0123456789abcdef');
});

it('is attached to the default production channel', function (): void {
    expect(config('logging.channels.stderr.tap'))->toContain(App\Logging\RedactSecrets::class);
});
