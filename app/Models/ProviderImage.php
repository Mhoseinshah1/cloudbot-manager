<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A provider operating-system image, synchronised from the provider.
 *
 * @property string $provider_image_id
 * @property bool $deprecated
 * @property bool $enabled
 * @property array<string, mixed>|null $metadata
 */
class ProviderImage extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'provider_id', 'provider_image_id', 'name', 'os_family', 'version',
        'architecture', 'deprecated', 'enabled', 'metadata',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = ['enabled' => true, 'deprecated' => false];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['deprecated' => 'boolean', 'enabled' => 'boolean', 'metadata' => 'array'];
    }

    /**
     * @return BelongsTo<Provider, $this>
     */
    public function provider(): BelongsTo
    {
        return $this->belongsTo(Provider::class);
    }
}
