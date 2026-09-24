<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Enums\Permission;
use App\Filament\Resources\ProvisioningAttemptResource\Pages;
use App\Filament\Support\AuthorizesWithPermission;
use App\Models\ProvisioningAttempt;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Every attempt to build a machine, and what came of it.
 *
 * The first thing to read when an order did not deliver. Read-only: an attempt
 * is a record of something that already happened, and the safe metadata shown
 * here never includes a provider's raw response or any credential.
 */
class ProvisioningAttemptResource extends Resource
{
    use AuthorizesWithPermission;

    protected static ?string $model = ProvisioningAttempt::class;

    protected static ?string $navigationIcon = 'heroicon-o-clock';

    protected static ?string $navigationGroup = 'Operations';

    protected static ?int $navigationSort = 22;

    protected static ?string $navigationLabel = 'Provisioning Attempts';

    public static function viewPermission(): Permission
    {
        return Permission::ProvisioningView;
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
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['order']))
            ->columns([
                Tables\Columns\TextColumn::make('id')->label('ID')->sortable(),
                Tables\Columns\TextColumn::make('order.order_number')->label('Order')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('stage')->badge()->sortable(),
                Tables\Columns\TextColumn::make('status')->badge()->sortable(),
                Tables\Columns\TextColumn::make('attempt_number')->label('Attempt')->numeric()->sortable(),
                Tables\Columns\TextColumn::make('error_category')->label('Error')->badge()->sortable()->placeholder('—'),
                Tables\Columns\TextColumn::make('started_at')->dateTime()->sortable()->toggleable(),
                Tables\Columns\TextColumn::make('finished_at')->dateTime()->sortable()->placeholder('—'),
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
     * @return array<string, class-string>
     */
    public static function getPages(): array
    {
        return [
            'index' => Pages\ListProvisioningAttempts::route('/'),
        ];
    }
}
