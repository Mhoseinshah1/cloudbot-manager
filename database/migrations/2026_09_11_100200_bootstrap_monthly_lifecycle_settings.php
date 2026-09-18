<?php

declare(strict_types=1);

use App\Enums\SettingKey;
use App\Models\Setting;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The initial values the monthly lifecycle needs in order to do anything.
 *
 * Every lifecycle decision reads these from the database and fails closed when
 * they are missing: no warning is sent, no grace is granted and nothing is
 * deleted. That is the right behaviour for a malformed row, and the wrong
 * experience for a fresh install, which would otherwise ship with the lifecycle
 * silently inert until somebody remembered to configure it.
 *
 * These are the specification's initial defaults, and they are *initial* only.
 * The insert ignores a key that already exists, so an operator who has set
 * `[7, 3, 1]`, `48` or `false` keeps exactly that through every later deploy —
 * a bootstrap that rewrote a configured value on update would be a bootstrap
 * that quietly turned automatic termination back on.
 *
 * Written straight to the table rather than through `SettingsService::set()`,
 * which records an audit entry against an acting administrator. A migration
 * has no administrator, and an audit line attributing a default to nobody
 * would read as a change somebody made.
 */
return new class extends Migration
{
    /**
     * @return list<array{key: SettingKey, value: array<int, int>|int|bool}>
     */
    private function defaults(): array
    {
        return [
            ['key' => SettingKey::MonthlyExpiryWarningDays, 'value' => [3, 1]],
            ['key' => SettingKey::MonthlyGraceHours, 'value' => 72],
            ['key' => SettingKey::AutoTerminateExpiredServers, 'value' => true],
        ];
    }

    public function up(): void
    {
        $now = now();

        foreach ($this->defaults() as $default) {
            $key = $default['key'];

            // Insert-if-missing at the database, on the unique key. Two
            // deployments racing this migration cannot both insert, and an
            // existing row — whatever its value — is left untouched.
            DB::table('settings')->insertOrIgnore([
                'key' => $key->value,
                'value' => Setting::encode($default['value'], $key->type()),
                'type' => $key->type()->value,
                'updated_by_admin_id' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        // Removes only what this migration put there. A value an operator has
        // since changed is their configuration, not this migration's, and a
        // rollback must not take it with it.
        foreach ($this->defaults() as $default) {
            $key = $default['key'];

            DB::table('settings')
                ->where('key', $key->value)
                ->where('type', $key->type()->value)
                ->where('value', Setting::encode($default['value'], $key->type()))
                ->delete();
        }
    }
};
