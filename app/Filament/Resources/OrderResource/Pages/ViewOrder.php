<?php

declare(strict_types=1);

namespace App\Filament\Resources\OrderResource\Pages;

use App\Filament\Resources\OrderResource;
use Filament\Actions\Action;
use Filament\Infolists\Components\KeyValueEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Resources\Pages\ViewRecord;

/**
 * One order, with the snapshots it was priced against.
 *
 * The cost and pricing snapshots are the frozen record of what this purchase
 * was quoted at, which is exactly what somebody investigating a disputed charge
 * needs. They hold identifiers and decimal strings — no credential ever reaches
 * them.
 */
class ViewOrder extends ViewRecord
{
    protected static string $resource = OrderResource::class;

    public function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            TextEntry::make('order_number')->label('Order'),
            TextEntry::make('user.name')->label('Customer'),
            TextEntry::make('status')->badge(),
            TextEntry::make('total_toman')->label('Amount (Toman)')->numeric(),
            TextEntry::make('attempts')->numeric(),
            TextEntry::make('provisioning_uuid')->label('Provisioning token')->placeholder('—'),
            TextEntry::make('failure_category')->label('Failure')->placeholder('—'),
            TextEntry::make('server.name')->label('Server')->placeholder('—'),
            TextEntry::make('created_at')->dateTime(),
            KeyValueEntry::make('cost_snapshot')->label('Cost snapshot')->columnSpanFull(),
            KeyValueEntry::make('pricing_snapshot')->label('Pricing snapshot')->columnSpanFull(),
        ]);
    }

    /**
     * The same three operational actions the listing offers, built as page
     * actions so they mount against this record rather than a table row.
     *
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            OrderResource::reconcileAction(Action::class),
            OrderResource::retryAction(Action::class),
            OrderResource::refundAction(Action::class),
        ];
    }
}
