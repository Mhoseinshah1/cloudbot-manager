<?php

declare(strict_types=1);

namespace App\Filament\Resources\ProductLocationPriceResource\Pages;

use App\Filament\Resources\ProductLocationPriceResource;
use Filament\Resources\Pages\ListRecords;

class ListProductLocationPrices extends ListRecords
{
    protected static string $resource = ProductLocationPriceResource::class;
}
