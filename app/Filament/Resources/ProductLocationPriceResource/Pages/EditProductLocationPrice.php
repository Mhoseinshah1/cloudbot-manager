<?php

declare(strict_types=1);

namespace App\Filament\Resources\ProductLocationPriceResource\Pages;

use App\Filament\Resources\ProductLocationPriceResource;
use Filament\Resources\Pages\EditRecord;

class EditProductLocationPrice extends EditRecord
{
    protected static string $resource = ProductLocationPriceResource::class;

    /** Deactivated, never deleted: orders were priced through this row. */
    protected function getHeaderActions(): array
    {
        return [];
    }
}
