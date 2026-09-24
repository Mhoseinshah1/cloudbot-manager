<?php

declare(strict_types=1);

namespace App\Filament\Resources\ProviderLocationResource\Pages;

use App\Filament\Resources\ProviderLocationResource;
use Filament\Resources\Pages\ListRecords;

class ListProviderLocations extends ListRecords
{
    protected static string $resource = ProviderLocationResource::class;
}
