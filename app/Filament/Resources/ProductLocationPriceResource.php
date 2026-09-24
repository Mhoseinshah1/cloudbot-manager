<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Enums\Permission;
use App\Filament\Resources\ProductLocationPriceResource\Pages;
use App\Filament\Support\AuthorizesWithPermission;
use App\Models\ProductLocationPrice;
use App\Models\ProviderImage;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * What each product costs a customer, in each location.
 *
 * The line between the two halves of this row is the important thing.
 * `selling_price_toman`, `active` and the default image are the operator's:
 * they are the business decision to sell here, at this price, with this OS.
 * `provider_cost_snapshot` and `provider_currency` are the provider's, written
 * by `providers:sync-pricing` — shown here because a margin is impossible to
 * judge without them, and read-only because a cost typed by hand is a margin
 * calculated against a number nobody can stand behind.
 *
 * The selling price is whole Toman, held as an integer. No float touches it.
 */
class ProductLocationPriceResource extends Resource
{
    use AuthorizesWithPermission;

    protected static ?string $model = ProductLocationPrice::class;

    protected static ?string $navigationIcon = 'heroicon-o-currency-dollar';

    protected static ?string $navigationGroup = 'Catalog';

    protected static ?int $navigationSort = 20;

    protected static ?string $navigationLabel = 'Product Location Prices';

    public static function viewPermission(): Permission
    {
        return Permission::ProvidersView;
    }

    public static function managePermission(): ?Permission
    {
        return Permission::ProviderCatalogManage;
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            TextInput::make('selling_price_toman')
                ->label('Selling price (Toman)')
                ->numeric()
                ->integer()
                ->minValue(0)
                ->required()
                ->helperText('Whole Toman. This is the customer price and nothing computes it for you.'),
            Select::make('default_image_id')
                ->label('Default image')
                ->options(fn (): array => ProviderImage::query()
                    ->where('enabled', true)
                    ->orderBy('name')
                    ->pluck('name', 'id')
                    ->all())
                ->searchable()
                ->required(),
            Toggle::make('active')->label('Sold in this location'),
            // Shown, never editable. Written by providers:sync-pricing.
            Placeholder::make('provider_cost_snapshot')
                ->label('Provider cost (synced)')
                ->content(fn (?ProductLocationPrice $record): string => $record?->provider_cost_snapshot === null
                    ? 'Not set — sales are refused until a pricing sync supplies it.'
                    : (string) $record->provider_cost_snapshot.' '.(string) $record->provider_currency),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['product', 'providerLocation']))
            ->columns([
                Tables\Columns\TextColumn::make('product.name')->label('Product')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('providerLocation.name')->label('Location')->searchable(),
                Tables\Columns\TextColumn::make('selling_price_toman')->label('Price')->numeric()->sortable()->suffix(' T'),
                Tables\Columns\TextColumn::make('provider_cost_snapshot')
                    ->label('Cost')
                    // Null here is operationally meaningful: PricingService
                    // refuses a sale without it.
                    ->placeholder('missing')
                    ->color(fn (?string $state): string => $state === null ? 'danger' : 'gray'),
                Tables\Columns\TextColumn::make('provider_currency')->label('Ccy')->toggleable(),
                Tables\Columns\IconColumn::make('active')->boolean()->sortable(),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('active'),
                Tables\Filters\Filter::make('missing_cost')
                    ->label('Missing provider cost')
                    ->query(fn (Builder $query): Builder => $query->whereNull('provider_cost_snapshot')),
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
            'index' => Pages\ListProductLocationPrices::route('/'),
            'edit' => Pages\EditProductLocationPrice::route('/{record}/edit'),
        ];
    }
}
