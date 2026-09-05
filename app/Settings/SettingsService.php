<?php

declare(strict_types=1);

namespace App\Settings;

use App\Audit\AuditEvent;
use App\Audit\AuditRecorder;
use App\Enums\Permission;
use App\Enums\SettingKey;
use App\Enums\SettingType;
use App\Models\Setting;
use App\Models\User;
use App\Settings\Exceptions\InvalidSettingValue;
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
     * A string setting, or null if it is absent or empty.
     *
     * An empty string is treated as absent rather than as a value: a terms
     * version of "" is nothing anybody can accept, and returning it would let
     * a customer agree to it.
     */
    public function string(SettingKey $key): ?string
    {
        $value = $this->typed($key);

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return $value;
    }

    /**
     * Store a setting. Privileged: these are the controls on the business.
     *
     * The value must already be the type the key declares. Nothing here
     * converts one type to another, because the conversions PHP would apply
     * are the dangerous ones: `"false"` is a non-empty string and therefore
     * truthy, so coercing it would store `true` and enable selling using the
     * word for off. A caller that has the wrong type has a bug, and the write
     * refusing is how they find out.
     *
     * @param  bool|int|float|string|array<array-key, mixed>|null  $value
     *
     * @throws InvalidSettingValue when the value is not what the key declares.
     */
    public function set(SettingKey $key, bool|int|float|string|array|null $value, User $actor): Setting
    {
        $this->assertMayManage($actor);

        // Before the transaction opens, so a rejected write touches nothing:
        // no row created, no row updated, no audit entry.
        $this->assertValueMatches($key, $value);

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
     * Refuse a value that is not the type this key declares.
     *
     * Checked with `is_*` rather than by attempting a cast, so `1`, `"1"` and
     * `1.0` are all refused for a boolean key instead of quietly becoming
     * `true`. Null is allowed only where a key has no range rule of its own:
     * clearing a setting is a legitimate act, and the readers already treat
     * an empty value as unreadable rather than as permission.
     *
     * @param  bool|int|float|string|array<array-key, mixed>|null  $value
     */
    private function assertValueMatches(SettingKey $key, bool|int|float|string|array|null $value): void
    {
        if ($value === null) {
            return;
        }

        $matches = match ($key->type()) {
            // is_bool, not a truthiness test. This is the check that stops the
            // string "false" from switching sales on.
            SettingType::Boolean => is_bool($value),
            // is_int excludes bools, which PHP would otherwise accept as 1/0,
            // and excludes "60" and 60.0.
            SettingType::Integer => is_int($value),
            SettingType::Float => is_float($value) || is_int($value),
            SettingType::String => is_string($value),
            SettingType::Json => is_array($value),
        };

        if (! $matches) {
            throw InvalidSettingValue::wrongType($key, $value);
        }

        // Range rules that belong to one key rather than to its type.
        if ($key === SettingKey::FxMaxAgeMinutes && is_int($value) && $value < 0) {
            // A negative freshness limit describes no rate at all, so every
            // sale would stop. Better to refuse the write than to have someone
            // discover the typo by watching sales fail.
            throw InvalidSettingValue::outOfRange($key, 'zero or more minutes');
        }
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
