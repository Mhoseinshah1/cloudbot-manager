<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A cloud provider this installation can buy from.
 *
 * The `code` names an entry in the static registry in config/providers.php.
 * This model never says which PHP class implements the provider.
 *
 * @property string $code
 * @property string $name
 * @property bool $enabled
 * @property array<string, mixed>|null $settings
 */
class Provider extends Model
{
    /** @use HasFactory<\Database\Factories\ProviderFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = ['code', 'name', 'enabled', 'settings'];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = ['enabled' => true];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'settings' => 'array',
        ];
    }

    /**
     * The credential set currently in use.
     *
     * @return HasOne<ProviderCredential, $this>
     */
    public function activeCredential(): HasOne
    {
        return $this->hasOne(ProviderCredential::class)->ofMany(
            ['id' => 'max'],
            static fn (Builder $query): Builder => $query->where('is_active', true),
        );
    }

    /**
     * @return HasMany<ProviderCredential, $this>
     */
    public function credentials(): HasMany
    {
        return $this->hasMany(ProviderCredential::class);
    }

    /**
     * @return HasMany<ProviderLocation, $this>
     */
    public function locations(): HasMany
    {
        return $this->hasMany(ProviderLocation::class);
    }

    /**
     * @return HasMany<ProviderPlan, $this>
     */
    public function plans(): HasMany
    {
        return $this->hasMany(ProviderPlan::class);
    }

    /**
     * @return HasMany<ProviderImage, $this>
     */
    public function images(): HasMany
    {
        return $this->hasMany(ProviderImage::class);
    }
}
