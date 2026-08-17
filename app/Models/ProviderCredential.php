<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProviderCredential extends Model
{
    use HasFactory;

    protected $fillable = [
        'provider_id',
        'name',
        'credentials',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            // Stored as encrypted JSON; never exposed to the UI.
            'credentials' => 'encrypted:array',
            'is_active' => 'boolean',
        ];
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(Provider::class);
    }
}
