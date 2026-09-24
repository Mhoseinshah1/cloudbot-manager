<?php

declare(strict_types=1);

namespace App\Filament\Resources\UserResource\Pages;

use App\Filament\Resources\UserResource;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Resources\Pages\ViewRecord;

/**
 * One customer.
 *
 * Deliberately excludes the password hash, the two-factor secret and the
 * recovery codes: nothing an operator needs to answer a customer is in them,
 * and a screen that shows a credential is a screen somebody screenshots.
 */
class ViewUser extends ViewRecord
{
    protected static string $resource = UserResource::class;

    public function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            TextEntry::make('id')->label('ID'),
            TextEntry::make('name'),
            TextEntry::make('status')->badge(),
            TextEntry::make('wallet_balance_toman')->label('Wallet (Toman)')->numeric(),
            TextEntry::make('created_via')->label('Source')->placeholder('—'),
            TextEntry::make('telegramAccounts.telegram_user_id')->label('Telegram ID')->placeholder('—'),
            TextEntry::make('telegramAccounts.username')->label('Telegram username')->placeholder('—'),
            TextEntry::make('telegramAccounts.bot_blocked_at')->label('Bot blocked at')->dateTime()->placeholder('—'),
            TextEntry::make('created_at')->dateTime(),
        ]);
    }
}
