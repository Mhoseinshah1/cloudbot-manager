<?php

declare(strict_types=1);

namespace App\Settings;

use App\Audit\AuditEvent;
use App\Audit\AuditRecorder;
use App\Enums\Permission;
use App\Enums\SettingKey;
use App\Models\Setting;
use App\Models\User;
use App\Settings\Exceptions\UnauthorizedSettingChange;
use Illuminate\Support\Facades\DB;

/**
 * Reads and writes business settings.
 *
 * Centralised so that the code deciding whether to sell does not also decide
 * how a setting is stored, how a missing one is interpreted, or what a
 * malformed one means. Those answers must be the same everywhere, and the
 * important one is that an unreadable setting is never treated as permission.
 *
 * Every reader here returns null for absent-or-malformed rather than throwing
 * or substituting a default. The caller decides what null means for its own
 * decision; for anything gating a sale, it means no.
 */
final readonly class SettingsService
{
    public function __construct(private AuditRecorder $audit) {}

    /**
     * A boolean setting, or null if it is absent or not a readable boolean.
     *
     * Deliberately strict. `sales.enabled` holding the string "yes" is a
     * misconfiguration, and reading it as true would turn selling back on
     * because someone typed the wrong word.
     */
    public function boolean(SettingKey $key): ?bool
    {
        $setting = $this->find($key);

        if ($setting === null || $setting->value === null) {
            return null;
        }

        // Only the two words that mean something. Anything else is a value
        // nobody can read, and reading it as `false` would report "an operator
        // turned sales off" when in fact the row is garbage — which sends
        // whoever investigates looking for a decision that was never made.
        if (! in_array($setting->value, ['true', 'false'], strict: true)) {
            return null;
        }

        $value = $this->typed($key);

        return is_bool($value) ? $value : null;
    }

    /**
     * An integer setting, or null if it is absent or not a readable integer.
     */
    public function integer(SettingKey $key): ?int
    {
        $setting = $this->find($key);

        if ($setting === null || $setting->value === null) {
            return null;
        }

        // The cast would turn "abc" into 0 and "12 minutes" into 12. For a
        // threshold that gates sales, a value nobody can read must not become
        // a number somebody has to live with.
        if (preg_match('/^-?\d+$/', trim($setting->value)) !== 1) {
            return null;
        }

        $value = $this->typed($key);

        return is_int($value) ? $value : null;
    }

    /**
     * Store a setting. Privileged: these are the controls on the business.
     */
    public function set(SettingKey $key, string|int|float|bool|null $value, User $actor): Setting
    {
        $this->assertMayManage($actor);

        return DB::transaction(function () use ($key, $value, $actor): Setting {
            $existing = $this->find($key);
            $before = $existing?->value;

            $setting = Setting::query()->updateOrCreate(
                ['key' => $key->value],
                [
                    'value' => Setting::encode($value, $key->type()),
                    'type' => $key->type(),
                    'updated_by_admin_id' => $actor->getKey(),
                ],
            );

            // Inside the transaction: a control that changed without a record
            // of who changed it is the one you most want a record of.
            $this->audit->record(
                AuditEvent::SettingChanged,
                actor: $actor,
                subject: $setting,
                before: ['value' => $before],
                after: ['value' => $setting->value],
                metadata: ['key' => $key->value, 'type' => $key->type()->value],
            );

            return $setting;
        });
    }

    /**
     * @return string|int|float|bool|array<array-key, mixed>|null
     */
    private function typed(SettingKey $key): string|int|float|bool|array|null
    {
        $setting = $this->find($key);

        if ($setting === null || $setting->type !== $key->type()) {
            // A row stored under a different type than this key declares is
            // not this setting. Reading it anyway would be guessing.
            return null;
        }

        return $setting->typedValue();
    }

    private function find(SettingKey $key): ?Setting
    {
        $setting = Setting::query()->where('key', $key->value)->first();

        return $setting instanceof Setting ? $setting : null;
    }

    private function assertMayManage(User $actor): void
    {
        if (! $actor->isActive() || ! $actor->checkPermissionTo(Permission::SettingsManage->value)) {
            throw UnauthorizedSettingChange::forActor();
        }
    }
}
