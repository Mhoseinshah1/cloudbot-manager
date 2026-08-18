<?php

namespace App\Filament\Resources;

use App\Enums\BillingMode;
use App\Filament\Resources\ProductResource\Pages;
use App\Models\Product;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class ProductResource extends Resource
{
    protected static ?string $model = Product::class;

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Select::make('provider_id')
                    ->relationship('provider', 'name'),
                Forms\Components\Select::make('provider_plan_id')
                    ->relationship('providerPlan', 'name'),
                Forms\Components\TextInput::make('name')
                    ->required()
                    ->maxLength(255),
                Forms\Components\TextInput::make('slug')
                    ->required()
                    ->maxLength(255),
                Forms\Components\Textarea::make('description')
                    ->columnSpanFull(),
                Forms\Components\TextInput::make('status')
                    ->required()
                    ->maxLength(255)
                    ->default('active'),
                Forms\Components\Select::make('billing_cycle')
                    ->options([
                        'monthly' => 'Monthly',
                        'quarterly' => 'Quarterly',
                        'yearly' => 'Yearly',
                    ])
                    ->default('monthly')
                    ->required(),
                Forms\Components\Select::make('billing_mode')
                    ->options([
                        BillingMode::Monthly->value => 'Monthly',
                        BillingMode::Hourly->value => 'Hourly',
                        BillingMode::HourlyCapped->value => 'Hourly with monthly cap',
                    ])
                    ->default(BillingMode::Monthly->value)
                    ->required()
                    ->helperText('Customer billing mode. Hourly modes settle usage from the customer wallet; the initial order is wallet funding, not a usage charge.'),
                Forms\Components\Select::make('markup_strategy')
                    ->options([
                        'fixed' => 'Fixed toman markup',
                        'percentage' => 'Percentage markup',
                        'custom' => 'Custom price',
                    ])
                    ->default('percentage')
                    ->required(),
                Forms\Components\TextInput::make('markup_value')
                    ->required()
                    ->numeric()
                    ->default(0),
                Forms\Components\TextInput::make('price_toman')
                    ->numeric()
                    ->helperText('Explicit monthly price for the custom strategy.'),
                Forms\Components\TextInput::make('hourly_price_toman')
                    ->numeric()
                    ->minValue(1)
                    ->required(fn (Get $get): bool => in_array($get('billing_mode'), ['hourly', 'hourly_capped'], true))
                    ->visible(fn (Get $get): bool => in_array($get('billing_mode'), ['hourly', 'hourly_capped'], true))
                    ->helperText('Customer hourly selling price (toman per hour).'),
                Forms\Components\TextInput::make('monthly_cap_toman')
                    ->numeric()
                    ->minValue(1)
                    ->required(fn (Get $get): bool => $get('billing_mode') === 'hourly_capped')
                    ->visible(fn (Get $get): bool => $get('billing_mode') === 'hourly_capped')
                    ->helperText('Customer monthly cap for hourly_capped billing. Cap periods are anchored to the service start and reset only when the service billing period advances — never on calendar-month boundaries.'),
                Forms\Components\TextInput::make('lifecycle_policy'),
                Forms\Components\Toggle::make('enabled')
                    ->required(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('provider.name')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('providerPlan.name')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('name')
                    ->searchable(),
                Tables\Columns\TextColumn::make('slug')
                    ->searchable(),
                Tables\Columns\TextColumn::make('status')
                    ->searchable(),
                Tables\Columns\TextColumn::make('billing_cycle')
                    ->searchable(),
                Tables\Columns\TextColumn::make('billing_mode')
                    ->badge()
                    ->searchable(),
                Tables\Columns\TextColumn::make('markup_strategy')
                    ->searchable(),
                Tables\Columns\TextColumn::make('markup_value')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('price_toman')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\IconColumn::make('enabled')
                    ->boolean(),
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
            'index' => Pages\ListProducts::route('/'),
            'create' => Pages\CreateProduct::route('/create'),
            'edit' => Pages\EditProduct::route('/{record}/edit'),
        ];
    }
}
