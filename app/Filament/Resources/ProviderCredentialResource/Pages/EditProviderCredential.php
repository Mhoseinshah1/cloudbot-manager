<?php

namespace App\Filament\Resources\ProviderCredentialResource\Pages;

use App\Filament\Resources\ProviderCredentialResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditProviderCredential extends EditRecord
{
    protected static string $resource = ProviderCredentialResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
