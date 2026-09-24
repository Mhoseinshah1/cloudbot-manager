<?php

declare(strict_types=1);

namespace App\Filament\Resources\ProviderImageResource\Pages;

use App\Filament\Resources\ProviderImageResource;
use Filament\Resources\Pages\EditRecord;

class EditProviderImage extends EditRecord
{
    protected static string $resource = ProviderImageResource::class;

    /** Deleting a catalog row would orphan the servers built from it. */
    protected function getHeaderActions(): array
    {
        return [];
    }
}
