<?php

namespace App\Filament\Resources\ProviderImageResource\Pages;

use App\Filament\Resources\ProviderImageResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListProviderImages extends ListRecords
{
    protected static string $resource = ProviderImageResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
