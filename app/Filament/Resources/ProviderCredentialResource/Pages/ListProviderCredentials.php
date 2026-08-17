<?php

namespace App\Filament\Resources\ProviderCredentialResource\Pages;

use App\Filament\Resources\ProviderCredentialResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListProviderCredentials extends ListRecords
{
    protected static string $resource = ProviderCredentialResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
