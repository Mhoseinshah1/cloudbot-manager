<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ProviderPlanResource\Pages;
use App\Models\ProviderPlan;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class ProviderPlanResource extends Resource
{
    protected static ?string $model = ProviderPlan::class;

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Select::make('provider_id')
                    ->relationship('provider', 'name')
                    ->required(),
                Forms\Components\TextInput::make('provider_plan_id')
                    ->required()
                    ->maxLength(255),
                Forms\Components\TextInput::make('name')
                    ->required()
                    ->maxLength(255),
                Forms\Components\Textarea::make('description')
                    ->columnSpanFull(),
                Forms\Components\TextInput::make('vcpu')
                    ->required()
                    ->numeric()
                    ->default(1),
                Forms\Components\TextInput::make('ram_mb')
                    ->required()
                    ->numeric()
                    ->default(1024),
                Forms\Components\TextInput::make('disk_gb')
                    ->required()
                    ->numeric()
                    ->default(20),
                Forms\Components\TextInput::make('bandwidth_gb')
                    ->numeric(),
                Forms\Components\TextInput::make('price_monthly')
                    ->required()
                    ->numeric(),
                Forms\Components\TextInput::make('currency')
                    ->required()
                    ->maxLength(3)
                    ->default('EUR'),
                Forms\Components\TextInput::make('price_hourly')
                    ->numeric(),
                Forms\Components\Toggle::make('enabled')
                    ->required(),
                Forms\Components\TextInput::make('metadata'),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('provider.name')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('provider_plan_id')
                    ->searchable(),
                Tables\Columns\TextColumn::make('name')
                    ->searchable(),
                Tables\Columns\TextColumn::make('vcpu')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('ram_mb')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('disk_gb')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('bandwidth_gb')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('price_monthly')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('currency')
                    ->searchable(),
                Tables\Columns\TextColumn::make('price_hourly')
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
            'index' => Pages\ListProviderPlans::route('/'),
            'create' => Pages\CreateProviderPlan::route('/create'),
            'edit' => Pages\EditProviderPlan::route('/{record}/edit'),
        ];
    }
}
