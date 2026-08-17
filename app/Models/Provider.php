<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Provider extends Model
{
    use HasFactory;

    protected $fillable = [
        'code',
        'name',
        'class',
        'enabled',
        'capabilities',
        'settings',
    ];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'capabilities' => 'array',
            'settings' => 'array',
        ];
    }

    public function credentials(): HasMany
    {
        return $this->hasMany(ProviderCredential::class);
    }

    public function locations(): HasMany
    {
        return $this->hasMany(ProviderLocation::class);
    }

    public function plans(): HasMany
    {
        return $this->hasMany(ProviderPlan::class);
    }

    public function images(): HasMany
    {
        return $this->hasMany(ProviderImage::class);
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    public function servers(): HasMany
    {
        return $this->hasMany(Server::class);
    }

    public function catalogSyncs(): HasMany
    {
        return $this->hasMany(ProviderCatalogSync::class);
    }

    public function latestSync(): HasOne
    {
        return $this->hasOne(ProviderCatalogSync::class)->latestOfMany();
    }

    public function supports(string $capability): bool
    {
        /** @var array<string, bool> $capabilities */
        $capabilities = $this->capabilities ?? [];

        return (bool) ($capabilities[$capability] ?? false);
    }
}
