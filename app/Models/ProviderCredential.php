<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An API credential set for a provider.
 *
 * The credentials are encrypted at rest and hidden from serialisation. Nothing
 * here should ever reach a log, an audit entry, an exception or an admin
 * screen: a leaked provider token lets someone spend our money on servers.
 *
 * @property array<string, mixed> $credentials
 * @property bool $is_active
 */
class ProviderCredential extends Model
{
    /** @use HasFactory<\Database\Factories\ProviderCredentialFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = ['provider_id', 'credentials', 'is_active', 'last_validated_at'];

    /**
     * Kept out of toArray() and toJson() so an accidental response or log line
     * cannot carry the token.
     *
     * @var list<string>
     */
    protected $hidden = ['credentials'];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = ['is_active' => true];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'credentials' => 'encrypted:array',
            'is_active' => 'boolean',
            'last_validated_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Provider, $this>
     */
    public function provider(): BelongsTo
    {
        return $this->belongsTo(Provider::class);
    }
}
