<?php

declare(strict_types=1);

namespace App\Filament\Resources\ProviderLocationResource\Pages;

use App\Filament\Resources\ProviderLocationResource;
use Filament\Resources\Pages\EditRecord;

class EditProviderLocation extends EditRecord
{
    protected static string $resource = ProviderLocationResource::class;

    /** Deleting a catalog row would orphan the servers built from it. */
    protected function getHeaderActions(): array
    {
        return [];
    }
}
