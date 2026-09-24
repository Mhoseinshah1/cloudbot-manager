<?php

declare(strict_types=1);

namespace App\Filament\Resources\ServerResource\Pages;

use App\Filament\Resources\ServerResource;
use App\Models\Server;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Resources\Pages\ViewRecord;

/**
 * One machine.
 *
 * Whether a root password is stored is shown; the password itself is not, here
 * or anywhere else in this panel.
 */
class ViewServer extends ViewRecord
{
    protected static string $resource = ServerResource::class;

    public function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            TextEntry::make('name'),
            TextEntry::make('user.name')->label('Customer'),
            TextEntry::make('provider.code')->label('Provider'),
            TextEntry::make('provider_server_id')->label('Provider server ID'),
            TextEntry::make('provisioning_uuid')->label('Provisioning token')->placeholder('—'),
            TextEntry::make('status')->badge(),
            TextEntry::make('power_state')->label('Power')->badge(),
            TextEntry::make('ip_address')->label('IPv4')->placeholder('—'),
            TextEntry::make('ipv6_address')->label('IPv6')->placeholder('—'),
            TextEntry::make('root_credential')
                ->label('Root password')
                // Presence only. The value is revealed through the owner-only
                // audited flow, never from a staff screen.
                ->state(fn (Server $record): string => $record->root_password_encrypted === null
                    ? 'none stored'
                    : 'stored (revealed only through the audited owner flow)'),
            TextEntry::make('created_at')->dateTime(),
            TextEntry::make('terminated_at')->dateTime()->placeholder('—'),
        ]);
    }
}
