<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Enums\Permission;
use App\Filament\Resources\AuditLogResource\Pages;
use App\Filament\Support\AuthorizesWithPermission;
use App\Models\AuditLog;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * The append-only record of who did what.
 *
 * Strictly read-only, and the database refuses a change even if this class
 * did not. An audit log an operator can edit is not an audit log.
 */
class AuditLogResource extends Resource
{
    use AuthorizesWithPermission;

    protected static ?string $model = AuditLog::class;

    protected static ?string $navigationIcon = 'heroicon-o-document-magnifying-glass';

    protected static ?string $navigationGroup = 'System';

    protected static ?int $navigationSort = 90;

    protected static ?string $navigationLabel = 'Audit Logs';

    public static function viewPermission(): Permission
    {
        return Permission::AuditView;
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
            ->columns([
                Tables\Columns\TextColumn::make('id')->label('ID')->sortable(),
                Tables\Columns\TextColumn::make('event')->badge()->searchable()->sortable(),
                Tables\Columns\TextColumn::make('actor_type')->label('Actor type')->sortable()->toggleable()->placeholder('—'),
                Tables\Columns\TextColumn::make('actor_id')->label('Actor')->searchable()->sortable()->placeholder('—'),
                Tables\Columns\TextColumn::make('subject_type')->label('Subject')->sortable()->toggleable()->placeholder('—'),
                Tables\Columns\TextColumn::make('subject_id')->label('Subject ID')->searchable()->sortable()->placeholder('—'),
                Tables\Columns\TextColumn::make('created_at')->dateTime()->sortable(),
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
            'index' => Pages\ListAuditLogs::route('/'),
        ];
    }
}
