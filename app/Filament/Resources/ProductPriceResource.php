<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ProductPriceResource\Pages;
use App\Models\ProductPrice;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class ProductPriceResource extends Resource
{
    protected static ?string $model = ProductPrice::class;

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Select::make('product_id')
                    ->relationship('product', 'name')
                    ->required(),
                Forms\Components\TextInput::make('billing_cycle')
                    ->required()
                    ->maxLength(255)
                    ->default('monthly'),
                Forms\Components\TextInput::make('price_toman')
                    ->required()
                    ->numeric(),
                Forms\Components\TextInput::make('provider_cost')
                    ->required()
                    ->numeric(),
                Forms\Components\TextInput::make('provider_currency')
                    ->required()
                    ->maxLength(3)
                    ->default('EUR'),
                Forms\Components\TextInput::make('exchange_rate')
                    ->required()
                    ->numeric(),
                Forms\Components\TextInput::make('local_cost')
                    ->required()
                    ->numeric(),
                Forms\Components\TextInput::make('gross_margin')
                    ->required()
                    ->numeric(),
                Forms\Components\DateTimePicker::make('valid_from'),
                Forms\Components\DateTimePicker::make('valid_to'),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('product.name')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('billing_cycle')
                    ->searchable(),
                Tables\Columns\TextColumn::make('price_toman')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('provider_cost')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('provider_currency')
                    ->searchable(),
                Tables\Columns\TextColumn::make('exchange_rate')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('local_cost')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('gross_margin')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('valid_from')
                    ->dateTime()
                    ->sortable(),
                Tables\Columns\TextColumn::make('valid_to')
                    ->dateTime()
                    ->sortable(),
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                //
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListProductPrices::route('/'),
            'create' => Pages\CreateProductPrice::route('/create'),
            'edit' => Pages\EditProductPrice::route('/{record}/edit'),
        ];
    }
}
