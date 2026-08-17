<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ProviderImageResource\Pages;
use App\Models\ProviderImage;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class ProviderImageResource extends Resource
{
    protected static ?string $model = ProviderImage::class;

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Select::make('provider_id')
                    ->relationship('provider', 'name')
                    ->required(),
                Forms\Components\FileUpload::make('provider_image_id')
                    ->image()
                    ->required(),
                Forms\Components\TextInput::make('name')
                    ->required()
                    ->maxLength(255),
                Forms\Components\TextInput::make('os_family')
                    ->maxLength(255),
                Forms\Components\TextInput::make('os_distro')
                    ->maxLength(255),
                Forms\Components\TextInput::make('version')
                    ->maxLength(255),
                Forms\Components\TextInput::make('architecture')
                    ->maxLength(255),
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
                Tables\Columns\ImageColumn::make('provider_image_id'),
                Tables\Columns\TextColumn::make('name')
                    ->searchable(),
                Tables\Columns\TextColumn::make('os_family')
                    ->searchable(),
                Tables\Columns\TextColumn::make('os_distro')
                    ->searchable(),
                Tables\Columns\TextColumn::make('version')
                    ->searchable(),
                Tables\Columns\TextColumn::make('architecture')
                    ->searchable(),
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
            'index' => Pages\ListProviderImages::route('/'),
            'create' => Pages\CreateProviderImage::route('/create'),
            'edit' => Pages\EditProviderImage::route('/{record}/edit'),
        ];
    }
}
