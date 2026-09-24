<?php

declare(strict_types=1);

namespace App\Filament\Resources\ProviderPlanResource\Pages;

use App\Filament\Resources\ProviderPlanResource;
use Filament\Resources\Pages\ListRecords;

class ListProviderPlans extends ListRecords
{
    protected static string $resource = ProviderPlanResource::class;
}
