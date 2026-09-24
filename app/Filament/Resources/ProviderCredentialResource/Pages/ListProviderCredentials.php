<?php

declare(strict_types=1);

namespace App\Filament\Resources\ProviderCredentialResource\Pages;

use App\Filament\Resources\ProviderCredentialResource;
use Filament\Resources\Pages\ListRecords;

class ListProviderCredentials extends ListRecords
{
    protected static string $resource = ProviderCredentialResource::class;
}
