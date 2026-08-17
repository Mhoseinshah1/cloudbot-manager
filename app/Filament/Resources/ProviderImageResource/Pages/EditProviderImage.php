<?php

namespace App\Filament\Resources\ProviderImageResource\Pages;

use App\Filament\Resources\ProviderImageResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditProviderImage extends EditRecord
{
    protected static string $resource = ProviderImageResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
