<?php

declare(strict_types=1);

use App\Models\Provider;
use App\Models\ProviderCredential;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

it('encrypts credentials at rest', function (): void {
    $secret = 'live-provider-token-value-9876';

    $credential = ProviderCredential::factory()->create([
        'credentials' => ['api_token' => $secret],
    ]);

    // Read the raw column, bypassing the model's casts entirely.
    $stored = (string) DB::table('provider_credentials')->where('id', $credential->id)->value('credentials');

    expect($stored)->not->toContain($secret)
        ->and($credential->fresh()->credentials)->toBe(['api_token' => $secret]);
});

it('keeps credentials out of a serialised model', function (): void {
    // Models end up in responses, queue payloads and log context.
    $credential = ProviderCredential::factory()->create([
        'credentials' => ['api_token' => 'live-provider-token-value-9876'],
    ]);

    expect($credential->toArray())->not->toHaveKey('credentials')
        ->and(json_encode($credential))->not->toContain('live-provider-token-value-9876');
});

it('allows only one active credential set per provider', function (): void {
    // Enforced by a partial unique index rather than by application discipline,
    // because two concurrent activations both pass an application check.
    $provider = Provider::factory()->create();
    ProviderCredential::factory()->create(['provider_id' => $provider->id, 'is_active' => true]);

    expect(fn () => ProviderCredential::factory()->create([
        'provider_id' => $provider->id,
        'is_active' => true,
    ]))->toThrow(QueryException::class);
});

it('keeps superseded credential sets as inactive history', function (): void {
    $provider = Provider::factory()->create();

    ProviderCredential::factory()->count(3)->inactive()->create(['provider_id' => $provider->id]);
    $active = ProviderCredential::factory()->create(['provider_id' => $provider->id]);

    expect($provider->credentials()->count())->toBe(4)
        ->and($provider->activeCredential->is($active))->toBeTrue();
});

it('lets a rotation activate a new set once the old one is stood down', function (): void {
    $provider = Provider::factory()->create();
    $old = ProviderCredential::factory()->create(['provider_id' => $provider->id]);

    $old->forceFill(['is_active' => false])->save();
    $new = ProviderCredential::factory()->create(['provider_id' => $provider->id]);

    expect($provider->fresh()->activeCredential->is($new))->toBeTrue()
        ->and($provider->credentials()->count())->toBe(2);
});

it('allows different providers to each hold an active set', function (): void {
    $first = Provider::factory()->create(['code' => 'fake']);
    $second = Provider::factory()->create(['code' => 'other']);

    ProviderCredential::factory()->create(['provider_id' => $first->id]);
    ProviderCredential::factory()->create(['provider_id' => $second->id]);

    expect(ProviderCredential::query()->where('is_active', true)->count())->toBe(2);
});

it('does not write the credential when one is logged', function (): void {
    // Logging a model is a normal thing to do while debugging; it must not be
    // the thing that leaks a provider token.
    $logFile = storage_path('logs/credential-test.log');
    @unlink($logFile);

    config()->set('logging.channels.credential_test', [
        'driver' => 'single',
        'path' => $logFile,
        'level' => 'debug',
        'tap' => [App\Logging\RedactSecrets::class],
    ]);

    $credential = ProviderCredential::factory()->create([
        'credentials' => ['api_token' => 'live-provider-token-value-9876'],
    ]);

    Log::channel('credential_test')->info('Provider credential rotated.', [
        'provider_id' => $credential->provider_id,
        'credential' => $credential->toArray(),
        'credentials' => $credential->credentials,
    ]);

    $written = (string) file_get_contents($logFile);
    @unlink($logFile);

    expect($written)->not->toContain('live-provider-token-value-9876');
});

it('removes credentials with the provider without echoing them', function (): void {
    $provider = Provider::factory()->create();
    $credential = ProviderCredential::factory()->create(['provider_id' => $provider->id]);

    $provider->delete();

    expect(ProviderCredential::query()->find($credential->id))->toBeNull();
});
