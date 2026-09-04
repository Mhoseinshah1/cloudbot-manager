<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A provider location, synchronised from the provider.
 *
 * Provider-supplied fields are refreshed by a sync and should be treated as
 * read-only locally; `enabled` is ours and must survive one.
 *
 * @property string $provider_location_id
 * @property bool $enabled
 * @property bool $available
 * @property array<string, mixed>|null $metadata
 */
class ProviderLocation extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'provider_id', 'provider_location_id', 'name', 'country_code', 'city',
        'enabled', 'available', 'metadata',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = ['enabled' => true, 'available' => true];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['enabled' => 'boolean', 'available' => 'boolean', 'metadata' => 'array'];
    }

    /**
     * @return BelongsTo<Provider, $this>
     */
    public function provider(): BelongsTo
    {
        return $this->belongsTo(Provider::class);
    }
}
