<?php

declare(strict_types=1);

namespace App\Filament\Resources\ProvisioningAttemptResource\Pages;

use App\Filament\Resources\ProvisioningAttemptResource;
use Filament\Resources\Pages\ListRecords;

class ListProvisioningAttempts extends ListRecords
{
    protected static string $resource = ProvisioningAttemptResource::class;
}
