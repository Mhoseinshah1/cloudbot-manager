<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Enums\Permission;
use App\Filament\Resources\TelegramAccountResource\Pages;
use App\Filament\Support\AuthorizesWithPermission;
use App\Models\TelegramAccount;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * The Telegram identity behind each customer.
 *
 * Read-only. These fields are how a person is recognised across every message
 * they send; editing one by hand would hand somebody else's conversation, and
 * servers, to the wrong account.
 */
class TelegramAccountResource extends Resource
{
    use AuthorizesWithPermission;

    protected static ?string $model = TelegramAccount::class;

    protected static ?string $navigationIcon = 'heroicon-o-chat-bubble-left-right';

    protected static ?string $navigationGroup = 'Customers';

    protected static ?int $navigationSort = 20;

    protected static ?string $navigationLabel = 'Telegram Accounts';

    public static function viewPermission(): Permission
    {
        return Permission::CustomersView;
    }

    // Nothing here may be changed from the panel.
    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(mixed $record): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['user']))
            ->columns([
                Tables\Columns\TextColumn::make('telegram_user_id')->label('Telegram ID')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('user.name')->label('Customer')->searchable()->sortable()->placeholder('—'),
                Tables\Columns\TextColumn::make('username')->searchable()->sortable()->placeholder('—'),
                Tables\Columns\TextColumn::make('first_name')->sortable()->toggleable()->placeholder('—'),
                Tables\Columns\TextColumn::make('last_name')->sortable()->toggleable()->placeholder('—'),
                Tables\Columns\TextColumn::make('telegram_chat_id')->label('Chat ID')->sortable()->toggleable()->placeholder('—'),
                Tables\Columns\TextColumn::make('bot_blocked_at')->label('Blocked')->dateTime()->sortable()->placeholder('—'),
                Tables\Columns\TextColumn::make('last_seen_at')->label('Last seen')->dateTime()->sortable()->placeholder('—'),
            ])
            ->filters([
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
            ])
            // History is never edited or removed in bulk.
            ->bulkActions([])
            ->defaultSort('id', 'desc');
    }

    /**
     * @return array<string, \Filament\Resources\Pages\PageRegistration>
     */
    public static function getPages(): array
    {
        return [
            'index' => Pages\ListTelegramAccounts::route('/'),
        ];
    }
}
