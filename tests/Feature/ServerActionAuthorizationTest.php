<?php

use App\Enums\BillingState;
use App\Models\Server;
use App\Models\ServerAction;
use App\Models\User;
use App\Services\ServerActionService;
use Illuminate\Auth\Access\AuthorizationException;
use RuntimeException;

it('does not allow a user to act on another users server', function () {
    $owner = User::factory()->create();
    $stranger = User::factory()->create();
    $server = Server::factory()->create(['user_id' => $owner->id]);

    expect(fn () => app(ServerActionService::class)->perform(
        $server,
        ServerAction::ACTION_REBOOT,
        $stranger,
    ))->toThrow(AuthorizationException::class);
});

it('rejects actions on deleted or deleting servers', function (string $status) {
    $owner = User::factory()->create();
    $server = Server::factory()->create([
        'user_id' => $owner->id,
        'status' => $status,
    ]);

    expect(fn () => app(ServerActionService::class)->perform(
        $server,
        ServerAction::ACTION_REBOOT,
        $owner,
    ))->toThrow(RuntimeException::class);
})->with([
    Server::STATUS_DELETING,
    Server::STATUS_DELETED,
]);

it('rejects actions on suspended servers', function () {
    $owner = User::factory()->create();
    $server = Server::factory()->create([
        'user_id' => $owner->id,
        'status' => Server::STATUS_SUSPENDED,
    ]);

    expect(fn () => app(ServerActionService::class)->perform(
        $server,
        ServerAction::ACTION_POWER_ON,
        $owner,
    ))->toThrow(RuntimeException::class);
});

it('rejects customer actions while a billing lifecycle action is pending', function () {
    $owner = User::factory()->create();
    $server = Server::factory()->create([
        'user_id' => $owner->id,
        'billing_mode' => 'hourly',
        'billing_state' => BillingState::LifecycleActionPending->value,
    ]);

    expect(fn () => app(ServerActionService::class)->perform(
        $server,
        ServerAction::ACTION_REBOOT,
        $owner,
    ))->toThrow(RuntimeException::class);
});

it('rejects a null actor unless an explicit authorized system context is supplied', function () {
    $server = Server::factory()->create();

    expect(fn () => app(ServerActionService::class)->perform(
        $server,
        ServerAction::ACTION_POWER_OFF,
        null,
    ))->toThrow(AuthorizationException::class);
});

it('rejects ownerless servers even for system initiated actions', function () {
    $server = Server::factory()->create();
    $server->forceFill(['user_id' => null])->save();

    expect(fn () => app(ServerActionService::class)->perform(
        $server,
        ServerAction::ACTION_DELETE,
        null,
        null,
        ServerActionService::SYSTEM_CONTEXT_BILLING_LIFECYCLE,
    ))->toThrow(AuthorizationException::class);
});

it('keeps the encrypted root password out of mass assignment and stores it through the explicit method', function () {
    $server = Server::factory()->create();

    expect($server->getFillable())->not->toContain('root_password_encrypted');

    $server->storeRootPassword('S3cret!');

    expect($server->fresh()->root_password_encrypted)->toBe('S3cret!');
});
