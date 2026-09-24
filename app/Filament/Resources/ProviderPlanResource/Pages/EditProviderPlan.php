<?php

declare(strict_types=1);

namespace App\Filament\Resources\ProviderPlanResource\Pages;

use App\Filament\Resources\ProviderPlanResource;
use Filament\Resources\Pages\EditRecord;

class EditProviderPlan extends EditRecord
{
    protected static string $resource = ProviderPlanResource::class;

    /** Deleting a catalog row would orphan the servers built from it. */
    protected function getHeaderActions(): array
    {
        return [];
    }
}
