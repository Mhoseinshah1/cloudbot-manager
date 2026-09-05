<?php

declare(strict_types=1);

use App\Authorization\RoleProvisioner;
use App\Cloud\Fake\Models\FakeProviderServer;
use App\Enums\OrderStatus;
use App\Enums\SettingKey;
use App\Enums\SettingType;
use App\Models\Server;
use App\Models\Setting;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Provisioning\Data\ProvisioningResult;
use App\Provisioning\ProvisioningService;
use App\Settings\SettingsService;
use Tests\Support\Provisioning\ProvisioningFloor;
use Tests\Support\Provisioning\Simulator;

/**
 * The switch that stops paid orders being sent to a provider.
 *
 * Separate from the sales switch on purpose. Sales decides whether new money
 * may be taken; this decides whether money already taken may be spent. During
 * an incident an operator usually wants exactly one of those, and one switch
 * would force a choice between refusing new customers and stranding paid ones.
 *
 * Off is a pause, never a verdict. A paused order keeps its money, keeps its
 * token and resumes when the switch comes back — because "we chose not to try"
 * is not evidence that a server cannot be built.
 */
beforeEach(function (): void {
    app(RoleProvisioner::class)->sync();

    $this->floor = ProvisioningFloor::open();
    $this->provisioning = app(ProvisioningService::class);
    $this->scripted = Simulator::script();
});

function provisionWithSetting(mixed $rawValue): ProvisioningResult
{
    // Written straight to the row, because the service refuses to store a
    // malformed value — and a malformed row is exactly what is being tested.
    Setting::query()->updateOrCreate(
        ['key' => SettingKey::ProvisioningEnabled->value],
        ['value' => $rawValue, 'type' => SettingType::Boolean],
    );

    return test()->provisioning->provision(test()->order);
}

beforeEach(function (): void {
    $this->order = $this->floor->paidOrder();
    $this->charged = (int) User::query()->whereKey($this->floor->customer->id)->value('wallet_balance_toman');
});

it('provisions normally when the switch is explicitly on', function (): void {
    $this->floor->setProvisioning(true);

    $result = $this->provisioning->provision($this->order);

    expect($result->state)->toBe(ProvisioningResult::Provisioned)
        ->and($this->scripted->callCount('createServer'))->toBe(1);
});

it('makes no provider call at all when the switch is off', function (): void {
    $this->floor->setProvisioning(false);

    $result = $this->provisioning->provision($this->order);

    expect($result->state)->toBe(ProvisioningResult::Paused)
        // Not one call of any kind. The pause is obeyed before anything is
        // asked of the provider.
        ->and($this->scripted->calls)->toBe([])
        ->and(FakeProviderServer::query()->count())->toBe(0)
        ->and(Server::query()->count())->toBe(0);
});

it('makes no provider call when the switch is missing', function (): void {
    Setting::query()->where('key', SettingKey::ProvisioningEnabled->value)->delete();

    $result = $this->provisioning->provision($this->order);

    // Fails closed. Nothing about a missing row says it is safe to start
    // spending money at a third party.
    expect($result->state)->toBe(ProvisioningResult::Paused)
        ->and($this->scripted->calls)->toBe([])
        ->and(FakeProviderServer::query()->count())->toBe(0);
});

it('makes no provider call when the switch is malformed', function (): void {
    foreach (['yes', 'TRUE', '1', 'on', '', 'maybe'] as $nonsense) {
        $result = provisionWithSetting($nonsense);

        expect($result->state)->toBe(ProvisioningResult::Paused, "value: {$nonsense}");
    }

    expect($this->scripted->calls)->toBe([])
        ->and(FakeProviderServer::query()->count())->toBe(0);
});

it('refunds nobody for being paused', function (): void {
    $this->floor->setProvisioning(false);

    $this->provisioning->provision($this->order);

    $fresh = $this->order->fresh();

    expect($fresh->status)->toBe(OrderStatus::Paid)
        ->and($fresh->failure_category)->toBeNull()
        ->and((int) User::query()->whereKey($this->floor->customer->id)->value('wallet_balance_toman'))
        ->toBe($this->charged)
        ->and(WalletTransaction::query()->where('idempotency_key', $this->order->refundIdempotencyKey())->count())
        ->toBe(0);
});

it('leaves a paused order exactly where it was, and resumes it later', function (): void {
    $this->floor->setProvisioning(false);
    $this->provisioning->provision($this->order);

    // Untouched: still paid, still without a token it never needed.
    expect($this->order->fresh()->status)->toBe(OrderStatus::Paid);

    $this->floor->setProvisioning(true);
    $result = $this->provisioning->provision($this->order->fresh());

    expect($result->state)->toBe(ProvisioningResult::Provisioned)
        ->and(FakeProviderServer::query()->count())->toBe(1)
        ->and(Server::query()->count())->toBe(1);
});

it('resumes an order that was already claimed, with the same token', function (): void {
    // Claimed first, then paused — the order already carries a token.
    $prepared = $this->provisioning->prepare($this->order);
    $token = $prepared->provisioning_uuid;

    $this->floor->setProvisioning(false);
    $paused = $this->provisioning->provision($this->order->fresh());

    expect($paused->state)->toBe(ProvisioningResult::Paused)
        ->and($this->scripted->calls)->toBe([])
        ->and($this->order->fresh()->provisioning_uuid)->toBe($token);

    $this->floor->setProvisioning(true);
    $this->provisioning->provision($this->order->fresh());

    expect($this->order->fresh()->provisioning_uuid)->toBe($token)
        ->and(FakeProviderServer::query()->firstOrFail()->provisioning_token)->toBe($token)
        ->and(FakeProviderServer::query()->count())->toBe(1);
});

it('ignores the sales switch for an order that is already paid', function (): void {
    // Sales being off must not strand a customer who has already paid: they
    // bought before the switch was thrown, and they are owed a server.
    app(SettingsService::class)->set(SettingKey::SalesEnabled, false, $this->floor->owner);

    $result = $this->provisioning->provision($this->order);

    expect($result->state)->toBe(ProvisioningResult::Provisioned)
        ->and(Server::query()->count())->toBe(1);
});

it('declares the provisioning switch as a strictly typed boolean setting', function (): void {
    expect(SettingKey::ProvisioningEnabled->type())->toBe(SettingType::Boolean)
        ->and(SettingKey::ProvisioningStuckAfterMinutes->type())->toBe(SettingType::Integer);

    // The write boundary refuses the string "false", which is truthy in PHP and
    // would otherwise switch provisioning on using the word for off.
    expect(fn () => app(SettingsService::class)
        ->set(SettingKey::ProvisioningEnabled, 'false', $this->floor->owner))
        ->toThrow(App\Settings\Exceptions\InvalidSettingValue::class);
});
