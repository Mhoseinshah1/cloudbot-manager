<?php

use App\Contracts\Data\ProviderImageData;
use App\Exceptions\ProviderConflictException;
use App\Exceptions\ProviderException;
use App\Models\AuditLog;
use App\Models\Provider;
use App\Models\ProviderCredential;
use App\Models\Server;
use App\Models\ServerAction;
use App\Models\User;
use App\Providers\Cloud\HetznerProvider;
use App\Services\ServerActionService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Fixtures\HetznerApiFixtures as F;

function hetznerServer(array $overrides = []): Server
{
    $provider = Provider::create([
        'code' => 'hetzner',
        'name' => 'Hetzner Cloud',
        'class' => HetznerProvider::class,
        'enabled' => true,
        'capabilities' => (new HetznerProvider)->capabilities(),
        'settings' => ['base_url' => F::BASE_URL, 'retry_attempts' => 1, 'retry_delay_ms' => 1],
    ]);

    ProviderCredential::create([
        'provider_id' => $provider->id,
        'name' => 'Production',
        'credentials' => ['token' => F::TOKEN],
        'is_active' => true,
    ]);

    $owner = User::factory()->create();

    return Server::create([
        'user_id' => $owner->id,
        'provider_id' => $provider->id,
        'provider_server_id' => '1234',
        'name' => 'my-server',
        'ip_address' => '1.2.3.4',
        'status' => Server::STATUS_RUNNING,
        'power_state' => 'running',
        ...$overrides,
    ]);
}

it('powers a server on through the Hetzner adapter with audit trail', function () {
    Http::fake(['api.hetzner.test/v1/servers/1234/actions/poweron' => Http::response(F::actionResponse('poweron'))]);

    $server = hetznerServer(['power_state' => 'off', 'status' => Server::STATUS_OFF]);
    $actor = User::factory()->create(['is_admin' => true]);

    app(ServerActionService::class)->perform($server, ServerAction::ACTION_POWER_ON, $actor);

    $record = ServerAction::where('server_id', $server->id)->first();
    expect($record->action)->toBe('power_on');
    expect($record->status)->toBe(ServerAction::STATUS_COMPLETED);

    expect($server->fresh()->power_state)->toBe('running');
    expect($server->fresh()->status)->toBe(Server::STATUS_RUNNING);

    expect(AuditLog::where('action', 'server.power_on')->count())->toBe(1);

    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/servers/1234/actions/poweron'));
});

it('denies actions to users who do not own the server', function () {
    $server = hetznerServer();
    $stranger = User::factory()->create();

    expect(fn () => app(ServerActionService::class)->perform($server, ServerAction::ACTION_REBOOT, $stranger))
        ->toThrow(AuthorizationException::class);
});

it('allows the owner to act on their own server', function () {
    Http::fake(['api.hetzner.test/v1/servers/1234/actions/reboot' => Http::response(F::actionResponse('reboot'))]);

    $server = hetznerServer();
    $owner = User::find($server->user_id);

    app(ServerActionService::class)->perform($server, ServerAction::ACTION_REBOOT, $owner);

    expect(ServerAction::where('server_id', $server->id)->where('action', 'reboot')->first()->status)
        ->toBe(ServerAction::STATUS_COMPLETED);
});

it('validates provider capability before calling the provider', function () {
    $server = hetznerServer();
    $server->provider->update(['capabilities' => [
        'supportsPowerOn' => false,
        'supportsPowerOff' => false,
        'supportsReboot' => true,
        'supportsRebuild' => true,
        'supportsResetPassword' => true,
        'supportsSnapshots' => false,
        'supportsSuspend' => false,
        'supportsUsage' => true,
    ]]);

    $actor = User::factory()->create(['is_admin' => true]);

    expect(fn () => app(ServerActionService::class)->perform($server, ServerAction::ACTION_POWER_ON, $actor))
        ->toThrow(ProviderException::class);

    Http::assertNothingSent();

    expect(ServerAction::where('server_id', $server->id)->count())->toBe(0);
});

it('requires an explicit image for rebuild', function () {
    $server = hetznerServer();
    $owner = User::findOrFail($server->user_id);

    expect(fn () => app(ServerActionService::class)->perform($server, ServerAction::ACTION_REBUILD, $owner))
        ->toThrow(InvalidArgumentException::class);

    expect(ServerAction::where('server_id', $server->id)->count())->toBe(0);
});

it('rebuilds the server with the given image and updates the snapshot', function () {
    Http::fake(['api.hetzner.test/v1/servers/1234/actions/rebuild' => Http::response(F::actionResponse('rebuild'))]);

    $server = hetznerServer();
    $actor = User::factory()->create(['is_admin' => true]);

    app(ServerActionService::class)->perform(
        $server,
        ServerAction::ACTION_REBUILD,
        $actor,
        new ProviderImageData(id: '1003', name: 'Debian 12', osDistro: 'debian', version: '12'),
    );

    expect($server->fresh()->image_snapshot['id'])->toBe('1003');
    expect(ServerAction::where('server_id', $server->id)->where('action', 'rebuild')->first()->status)
        ->toBe(ServerAction::STATUS_COMPLETED);
});

it('stores the reset password encrypted and returns it once for delivery', function () {
    Http::fake(['api.hetzner.test/v1/servers/1234/actions/reset_password' => Http::response(F::resetPasswordResponse())]);

    $server = hetznerServer();
    $owner = User::findOrFail($server->user_id);

    $result = app(ServerActionService::class)->perform($server, ServerAction::ACTION_RESET_PASSWORD, $owner);

    expect($result['password'])->toBe('new-ROOT-p4ssw0rd!');
    expect($server->fresh()->root_password_encrypted)->toBe('new-ROOT-p4ssw0rd!');
});

it('records failed actions with error details and an audit trail', function () {
    Http::fake(['api.hetzner.test/v1/servers/1234/actions/poweroff' => Http::response(F::error(409, 'conflict', 'action already in progress'), 409)]);

    $server = hetznerServer();
    $actor = User::factory()->create(['is_admin' => true]);

    expect(fn () => app(ServerActionService::class)->perform($server, ServerAction::ACTION_POWER_OFF, $actor))
        ->toThrow(ProviderConflictException::class);

    $record = ServerAction::where('server_id', $server->id)->where('action', 'power_off')->first();
    expect($record->status)->toBe(ServerAction::STATUS_FAILED);
    expect($record->error_message)->toContain('conflict');

    expect(AuditLog::where('action', 'server.power_off.failed')->count())->toBe(1);
});

it('deletes a server only via an explicit authorized request', function () {
    Http::fake(['api.hetzner.test/v1/servers/1234' => Http::response([], 204)]);

    $server = hetznerServer();
    $actor = User::factory()->create(['is_admin' => true]);

    app(ServerActionService::class)->perform($server, ServerAction::ACTION_DELETE, $actor);

    expect($server->fresh()->status)->toBe(Server::STATUS_DELETED);
    expect($server->fresh()->trashed())->toBeTrue();
});
