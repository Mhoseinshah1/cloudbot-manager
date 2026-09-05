<?php

declare(strict_types=1);

use App\Audit\AuditEvent;
use App\Authorization\RoleProvisioner;
use App\Enums\AdminRole;
use App\Enums\SettingKey;
use App\Models\AuditLog;
use App\Models\Setting;
use App\Models\User;
use App\Settings\Exceptions\InvalidSettingValue;
use App\Settings\Exceptions\UnauthorizedSettingChange;
use App\Settings\SettingsService;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    app(RoleProvisioner::class)->sync();

    $this->settings = app(SettingsService::class);
    $this->owner = User::factory()->create();
    $this->owner->assignRole(AdminRole::Owner->value);
});

/** The row as the database holds it, or null. */
function storedSetting(SettingKey $key): ?string
{
    return Setting::query()->where('key', $key->value)->value('value');
}

it('stores a boolean kill switch from a real bool', function (): void {
    $this->settings->set(SettingKey::SalesEnabled, true, $this->owner);
    expect($this->settings->boolean(SettingKey::SalesEnabled))->toBeTrue()
        ->and(storedSetting(SettingKey::SalesEnabled))->toBe('true');

    $this->settings->set(SettingKey::SalesEnabled, false, $this->owner);
    expect($this->settings->boolean(SettingKey::SalesEnabled))->toBeFalse()
        ->and(storedSetting(SettingKey::SalesEnabled))->toBe('false');
});

it('never lets the string "false" enable sales', function (): void {
    // The whole point. "false" is a non-empty string and therefore truthy, so
    // coercing it would store `true` and turn selling on using the word for
    // off — a kill switch that fails in the one direction that matters.
    $this->settings->set(SettingKey::SalesEnabled, false, $this->owner);

    expect(fn () => $this->settings->set(SettingKey::SalesEnabled, 'false', $this->owner))
        ->toThrow(InvalidSettingValue::class);

    expect(storedSetting(SettingKey::SalesEnabled))->toBe('false')
        ->and($this->settings->boolean(SettingKey::SalesEnabled))->toBeFalse();
});

it('refuses every non-bool offered for the kill switch', function (): void {
    foreach (['true', 'false', '1', '0', '', 'yes', 1, 0, 1.0, 0.0, ['on'], 60] as $bad) {
        expect(fn () => $this->settings->set(SettingKey::SalesEnabled, $bad, $this->owner))
            ->toThrow(InvalidSettingValue::class, '', 'value '.json_encode($bad));
    }

    expect(Setting::query()->count())->toBe(0);
});

it('stores a non-negative integer threshold', function (): void {
    foreach ([0, 1, 60, 1_440] as $good) {
        $this->settings->set(SettingKey::FxMaxAgeMinutes, $good, $this->owner);

        expect($this->settings->integer(SettingKey::FxMaxAgeMinutes))->toBe($good);
    }
});

it('refuses every non-int offered for the freshness threshold', function (): void {
    foreach (['60', '0', '', 60.0, 0.5, true, false, ['60']] as $bad) {
        expect(fn () => $this->settings->set(SettingKey::FxMaxAgeMinutes, $bad, $this->owner))
            ->toThrow(InvalidSettingValue::class, '', 'value '.json_encode($bad));
    }

    expect(Setting::query()->count())->toBe(0);
});

it('refuses a negative freshness threshold', function (): void {
    // A negative limit describes no rate at all, so every sale would stop.
    // Better refused at the write than discovered by watching sales fail.
    foreach ([-1, -60] as $bad) {
        expect(fn () => $this->settings->set(SettingKey::FxMaxAgeMinutes, $bad, $this->owner))
            ->toThrow(InvalidSettingValue::class);
    }

    expect(Setting::query()->count())->toBe(0);
});

it('leaves a previously valid value untouched when a write is refused', function (): void {
    $this->settings->set(SettingKey::SalesEnabled, true, $this->owner);
    $this->settings->set(SettingKey::FxMaxAgeMinutes, 120, $this->owner);

    $salesRow = (array) DB::table('settings')->where('key', SettingKey::SalesEnabled->value)->first();
    $fxRow = (array) DB::table('settings')->where('key', SettingKey::FxMaxAgeMinutes->value)->first();

    expect(fn () => $this->settings->set(SettingKey::SalesEnabled, 'false', $this->owner))
        ->toThrow(InvalidSettingValue::class);
    expect(fn () => $this->settings->set(SettingKey::FxMaxAgeMinutes, '5', $this->owner))
        ->toThrow(InvalidSettingValue::class);

    expect((array) DB::table('settings')->where('key', SettingKey::SalesEnabled->value)->first())->toBe($salesRow)
        ->and((array) DB::table('settings')->where('key', SettingKey::FxMaxAgeMinutes->value)->first())->toBe($fxRow);
});

it('writes no audit entry for a refused setting change', function (): void {
    expect(fn () => $this->settings->set(SettingKey::SalesEnabled, 'true', $this->owner))
        ->toThrow(InvalidSettingValue::class);

    expect(AuditLog::query()->where('event', AuditEvent::SettingChanged)->count())->toBe(0);
});

it('creates no row at all for a refused first write', function (): void {
    expect(fn () => $this->settings->set(SettingKey::FxMaxAgeMinutes, '60', $this->owner))
        ->toThrow(InvalidSettingValue::class);

    expect(Setting::query()->where('key', SettingKey::FxMaxAgeMinutes->value)->exists())->toBeFalse();
});

it('checks authorization before the value type', function (): void {
    // Someone with no right to change a control learns nothing about what
    // shape its value should be.
    $support = User::factory()->create();
    $support->assignRole(AdminRole::Support->value);

    expect(fn () => $this->settings->set(SettingKey::SalesEnabled, 'false', $support))
        ->toThrow(UnauthorizedSettingChange::class);

    expect(Setting::query()->count())->toBe(0);
});

it('names the key and the expected type in the refusal', function (): void {
    try {
        $this->settings->set(SettingKey::SalesEnabled, '1', $this->owner);
        $this->fail('A string was accepted for a boolean setting.');
    } catch (InvalidSettingValue $refusal) {
        expect($refusal->key)->toBe(SettingKey::SalesEnabled)
            ->and($refusal->getMessage())->toContain('sales.enabled')
            ->and($refusal->getMessage())->toContain('a bool')
            ->and($refusal->getMessage())->toContain('string');
    }
});

it('still allows clearing a setting to null', function (): void {
    $this->settings->set(SettingKey::SalesEnabled, true, $this->owner);
    $this->settings->set(SettingKey::SalesEnabled, null, $this->owner);

    // Cleared reads as unreadable, which the sales path already treats as no.
    expect($this->settings->boolean(SettingKey::SalesEnabled))->toBeNull();
});
