<?php

namespace App\Filament\Resources\ProviderLocationResource\Pages;

use App\Filament\Resources\ProviderLocationResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditProviderLocation extends EditRecord
{
    protected static string $resource = ProviderLocationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
