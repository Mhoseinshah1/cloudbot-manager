<?php

declare(strict_types=1);

use App\Enums\SettingType;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

it('rejects a duplicate key', function (): void {
    // The key is how a control is looked up; two rows would make the answer
    // depend on insertion order.
    Setting::create(['key' => 'sales_enabled', 'value' => 'true', 'type' => SettingType::Boolean]);

    expect(fn () => Setting::create(['key' => 'sales_enabled', 'value' => 'false', 'type' => SettingType::Boolean]))
        ->toThrow(QueryException::class);
});

it('round-trips each supported type', function (SettingType $type, string|int|float|bool|array $value): void {
    $setting = Setting::create([
        'key' => 'probe.'.$type->value,
        'value' => Setting::encode($value, $type),
        'type' => $type,
    ]);

    expect($setting->fresh()->typedValue())->toBe($value);
})->with([
    'string' => [SettingType::String, 'a value'],
    'integer' => [SettingType::Integer, 4320],
    'float' => [SettingType::Float, 1.5],
    'boolean true' => [SettingType::Boolean, true],
    'boolean false' => [SettingType::Boolean, false],
    'json' => [SettingType::Json, ['days' => [3, 1]]],
]);

it('treats only the exact string true as true', function (string $stored): void {
    // A kill switch must not be turned on by a typo. Anything that is not
    // exactly "true" is false.
    $setting = Setting::create(['key' => 'probe.'.md5($stored), 'value' => $stored, 'type' => SettingType::Boolean]);

    expect($setting->typedValue())->toBeFalse();
})->with(['false', 'TRUE', '1', 'yes', 'on', '']);

it('reads a null value back as null', function (): void {
    $setting = Setting::create(['key' => 'probe.null', 'value' => null, 'type' => SettingType::Integer]);

    expect($setting->typedValue())->toBeNull();
});

it('rejects a type the application does not define', function (): void {
    expect(fn () => DB::table('settings')->insert([
        'key' => 'probe.bad-type',
        'value' => 'x',
        'type' => 'timestamp',
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});

it('records which administrator last changed it', function (): void {
    $admin = User::factory()->create();

    $setting = Setting::create([
        'key' => 'provisioning_enabled',
        'value' => 'true',
        'type' => SettingType::Boolean,
        'updated_by_admin_id' => $admin->id,
    ]);

    expect($setting->updatedByAdmin->is($admin))->toBeTrue();
});

it('survives the administrator who set it being removed', function (): void {
    // Losing the person must not lose the configuration they made.
    $admin = User::factory()->create();
    $setting = Setting::create([
        'key' => 'monthly_grace_hours',
        'value' => '72',
        'type' => SettingType::Integer,
        'updated_by_admin_id' => $admin->id,
    ]);

    $admin->delete();
    $fresh = $setting->fresh();

    expect($fresh)->not->toBeNull()
        ->and($fresh->typedValue())->toBe(72)
        ->and($fresh->updated_by_admin_id)->toBeNull();
});

it('ships no seeded business values', function (): void {
    // Thresholds and kill switches arrive with the features that read them.
    // A value seeded now would be one nobody owns.
    $this->seed(Database\Seeders\DatabaseSeeder::class);

    expect(Setting::query()->count())->toBe(0);
});
