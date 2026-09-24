<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Enums\Permission;
use App\Filament\Resources\ServerActionResource\Pages;
use App\Filament\Support\AuthorizesWithPermission;
use App\Models\ServerAction;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Everything asked of a machine, and how it ended.
 *
 * Read-only history. Attempts, status and the retry barrier are the durable
 * state the executor and the reconciler decide from — editing one by hand is
 * how a delete gets sent twice.
 */
class ServerActionResource extends Resource
{
    use AuthorizesWithPermission;

    protected static ?string $model = ServerAction::class;

    protected static ?string $navigationIcon = 'heroicon-o-bolt';

    protected static ?string $navigationGroup = 'Operations';

    protected static ?int $navigationSort = 40;

    protected static ?string $navigationLabel = 'Server Actions';

    public static function viewPermission(): Permission
    {
        return Permission::ServerActionsView;
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
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['server']))
            ->columns([
                Tables\Columns\TextColumn::make('id')->label('ID')->sortable(),
                Tables\Columns\TextColumn::make('server.name')->label('Server')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('action')->badge()->sortable(),
                Tables\Columns\TextColumn::make('status')->badge()->sortable(),
                Tables\Columns\TextColumn::make('actor_type')->label('Actor')->sortable()->toggleable(),
                Tables\Columns\TextColumn::make('provider_action_id')->label('Provider action')->searchable()->sortable()->toggleable()->placeholder('—'),
                Tables\Columns\TextColumn::make('error_category')->label('Error')->badge()->sortable()->placeholder('—'),
                Tables\Columns\TextColumn::make('attempts')->numeric()->sortable(),
                Tables\Columns\TextColumn::make('retry_after')->dateTime()->sortable()->toggleable()->placeholder('—'),
                Tables\Columns\TextColumn::make('requested_at')->dateTime()->sortable(),
                Tables\Columns\TextColumn::make('settled_at')->dateTime()->sortable()->placeholder('—'),
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
     * @return array<string, class-string>
     */
    public static function getPages(): array
    {
        return [
            'index' => Pages\ListServerActions::route('/'),
        ];
    }
}
