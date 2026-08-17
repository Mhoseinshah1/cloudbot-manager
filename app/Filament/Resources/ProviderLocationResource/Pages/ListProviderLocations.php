<?php

namespace App\Filament\Resources\ProviderLocationResource\Pages;

use App\Filament\Resources\ProviderLocationResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListProviderLocations extends ListRecords
{
    protected static string $resource = ProviderLocationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
