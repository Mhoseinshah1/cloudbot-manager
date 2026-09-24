<?php

declare(strict_types=1);

namespace App\Filament\Resources\SubscriptionResource\Pages;

use App\Filament\Resources\SubscriptionResource;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Resources\Pages\ViewRecord;

class ViewSubscription extends ViewRecord
{
    protected static string $resource = SubscriptionResource::class;

    public function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            TextEntry::make('user.name')->label('Customer'),
            TextEntry::make('server.name')->label('Server')->placeholder('—'),
            TextEntry::make('status')->badge(),
            TextEntry::make('price_toman')->label('Price (Toman)')->numeric(),
            TextEntry::make('current_period_start')->dateTime(),
            TextEntry::make('current_period_end')->label('Expires')->dateTime(),
            TextEntry::make('grace_until')->dateTime()->placeholder('—'),
            TextEntry::make('billing_mode')->badge(),
            TextEntry::make('billing_cycle')->badge(),
            TextEntry::make('last_billed_at')->dateTime()->placeholder('—'),
        ]);
    }
}
