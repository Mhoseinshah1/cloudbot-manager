<?php

declare(strict_types=1);

namespace App\Filament\Resources\ServerActionResource\Pages;

use App\Filament\Resources\ServerActionResource;
use Filament\Resources\Pages\ListRecords;

class ListServerActions extends ListRecords
{
    protected static string $resource = ServerActionResource::class;
}
