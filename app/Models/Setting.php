<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\SettingType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A business control stored in the database.
 *
 * The value is held as text with a declared type so the table stays uniform
 * while reads stay unambiguous.
 *
 * @property string $key
 * @property string|null $value
 * @property SettingType $type
 */
class Setting extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'key',
        'value',
        'type',
        'updated_by_admin_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => SettingType::class,
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function updatedByAdmin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_admin_id');
    }

    /**
     * The stored text read back as its declared type.
     *
     * @return string|int|float|bool|array<array-key, mixed>|null
     */
    public function typedValue(): string|int|float|bool|array|null
    {
        if ($this->value === null) {
            return null;
        }

        return match ($this->type) {
            SettingType::String => $this->value,
            SettingType::Integer => (int) $this->value,
            SettingType::Float => (float) $this->value,
            // Only the exact string 'true' is true. A permissive cast here
            // would let a typo silently switch a kill switch on.
            SettingType::Boolean => $this->value === 'true',
            SettingType::Json => json_decode($this->value, true, 512, JSON_THROW_ON_ERROR),
        };
    }

    /**
     * Serialise a value for storage under the given type.
     *
     * @param  string|int|float|bool|array<array-key, mixed>|null  $value
     */
    public static function encode(string|int|float|bool|array|null $value, SettingType $type): ?string
    {
        if ($value === null) {
            return null;
        }

        return match ($type) {
            SettingType::Json => json_encode($value, JSON_THROW_ON_ERROR),
            SettingType::Boolean => $value ? 'true' : 'false',
            default => (string) $value,
        };
    }
}
