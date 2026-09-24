<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Enums\Permission;
use App\Filament\Resources\NotificationLogResource\Pages;
use App\Filament\Support\AuthorizesWithPermission;
use App\Models\NotificationLog;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * What the system tried to tell customers, and whether it arrived.
 *
 * Read-only. Delivery is the outbox's to drive; rewriting a status here would
 * claim a message was sent that never was.
 */
class NotificationLogResource extends Resource
{
    use AuthorizesWithPermission;

    protected static ?string $model = NotificationLog::class;

    protected static ?string $navigationIcon = 'heroicon-o-bell';

    protected static ?string $navigationGroup = 'System';

    protected static ?int $navigationSort = 70;

    protected static ?string $navigationLabel = 'Notifications';

    public static function viewPermission(): Permission
    {
        return Permission::NotificationsView;
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
                Tables\Columns\TextColumn::make('id')->label('ID')->sortable(),
                Tables\Columns\TextColumn::make('user.name')->label('Customer')->searchable()->sortable()->placeholder('—'),
                Tables\Columns\TextColumn::make('channel')->badge()->sortable(),
                Tables\Columns\TextColumn::make('type')->searchable()->sortable()->limit(40),
                Tables\Columns\TextColumn::make('status')->badge()->sortable(),
                Tables\Columns\TextColumn::make('error_category')->label('Error')->badge()->sortable()->placeholder('—'),
                Tables\Columns\TextColumn::make('summary')->sortable()->toggleable()->limit(50)->placeholder('—'),
                Tables\Columns\TextColumn::make('sent_at')->dateTime()->sortable()->placeholder('—'),
                Tables\Columns\TextColumn::make('created_at')->dateTime()->sortable()->toggleable(),
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
            'index' => Pages\ListNotificationLogs::route('/'),
        ];
    }
}
