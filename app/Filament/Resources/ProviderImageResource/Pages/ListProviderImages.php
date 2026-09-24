<?php

declare(strict_types=1);

namespace App\Filament\Resources\ProviderImageResource\Pages;

use App\Filament\Resources\ProviderImageResource;
use Filament\Resources\Pages\ListRecords;

class ListProviderImages extends ListRecords
{
    protected static string $resource = ProviderImageResource::class;
}
