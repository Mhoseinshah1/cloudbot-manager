<?php

declare(strict_types=1);

namespace App\Filament\Resources\TelegramAccountResource\Pages;

use App\Filament\Resources\TelegramAccountResource;
use Filament\Resources\Pages\ListRecords;

class ListTelegramAccounts extends ListRecords
{
    protected static string $resource = TelegramAccountResource::class;
}
