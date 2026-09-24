<?php

declare(strict_types=1);

namespace App\Filament\Resources\SubscriptionRenewalResource\Pages;

use App\Filament\Resources\SubscriptionRenewalResource;
use Filament\Resources\Pages\ListRecords;

class ListSubscriptionRenewals extends ListRecords
{
    protected static string $resource = SubscriptionRenewalResource::class;
}
