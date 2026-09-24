<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Enums\Permission;
use App\Filament\Resources\ProviderLocationResource\Pages;
use App\Filament\Support\AuthorizesWithPermission;
use App\Models\ProviderLocation;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Where each provider will build machines.
 *
 * Every field but `enabled` belongs to the provider and is written by
 * `providers:sync`. `enabled` is the operator's own decision not to sell here,
 * and is kept distinct from `available`, which is the provider saying it has no
 * capacity.
 */
class ProviderLocationResource extends Resource
{
    use AuthorizesWithPermission;

    protected static ?string $model = ProviderLocation::class;

    protected static ?string $navigationIcon = 'heroicon-o-globe-alt';

    protected static ?string $navigationGroup = 'Catalog';

    protected static ?int $navigationSort = 30;

    protected static ?string $navigationLabel = 'Provider Locations';

    public static function viewPermission(): Permission
    {
        return Permission::ProvidersView;
    }

    public static function managePermission(): ?Permission
    {
        return Permission::ProviderCatalogManage;
    }

    /** Rows arrive from the provider sync, never from an operator. */
    public static function canCreate(): bool
    {
        return false;
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            // The one local decision. Everything else on this row is the
            // provider's and is rewritten by the next sync.
            Toggle::make('enabled')
                ->label('Enabled')
                ->helperText('Our own switch. Provider-owned fields are synced and cannot be edited here.'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('provider'))
            ->columns([
                Tables\Columns\TextColumn::make('provider.code')->label('Provider')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('provider_location_id')->label('Provider ID')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('name')->label('Name')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('city')->label('City')->sortable()->toggleable(),
                Tables\Columns\TextColumn::make('country_code')->label('Country')->sortable()->toggleable(),
                Tables\Columns\IconColumn::make('available')->label('Available')->boolean()->sortable(),
                Tables\Columns\IconColumn::make('enabled')->label('Enabled')->boolean()->sortable(),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('enabled'),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([])
            ->defaultSort('id');
    }

    /**
     * @return array<string, \Filament\Resources\Pages\PageRegistration>
     */
    public static function getPages(): array
    {
        return [
            'index' => Pages\ListProviderLocations::route('/'),
            'edit' => Pages\EditProviderLocation::route('/{record}/edit'),
        ];
    }
}
