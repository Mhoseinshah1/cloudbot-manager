<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ProviderResource\Pages;
use App\Jobs\SyncProviderCatalogJob;
use App\Models\Provider;
use App\Models\ProviderCatalogSync;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ProviderResource extends Resource
{
    protected static ?string $model = Provider::class;

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('code')
                    ->required()
                    ->maxLength(255),
                Forms\Components\TextInput::make('name')
                    ->required()
                    ->maxLength(255),
                Forms\Components\TextInput::make('class')
                    ->required()
                    ->maxLength(255),
                Forms\Components\Toggle::make('enabled')
                    ->required(),
                Forms\Components\TextInput::make('capabilities'),
                Forms\Components\TextInput::make('settings'),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('code')
                    ->searchable(),
                Tables\Columns\TextColumn::make('name')
                    ->searchable(),
                Tables\Columns\TextColumn::make('class')
                    ->searchable(),
                Tables\Columns\IconColumn::make('enabled')
                    ->boolean(),
                Tables\Columns\TextColumn::make('latestSync.status')
                    ->label('Sync status')
                    ->badge()
                    ->color(fn (?string $state): string => match ($state) {
                        ProviderCatalogSync::STATUS_RUNNING => 'warning',
                        ProviderCatalogSync::STATUS_COMPLETED => 'success',
                        ProviderCatalogSync::STATUS_FAILED => 'danger',
                        default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('latestSync.started_at')
                    ->label('Last sync')
                    ->dateTime()
                    ->placeholder('Never'),
                Tables\Columns\TextColumn::make('latestSync.finished_at')
                    ->label('Sync finished')
                    ->dateTime()
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
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
                Tables\Actions\Action::make('syncCatalog')
                    ->label('Sync Hetzner Catalog')
                    ->icon('heroicon-o-arrow-path')
                    ->visible(fn (Provider $record): bool => $record->code === 'hetzner')
                    ->action(function (Provider $record): void {
                        SyncProviderCatalogJob::dispatch($record);

                        Notification::make()
                            ->title('Catalog sync queued')
                            ->body("Provider [{$record->name}] sync is running in the background.")
                            ->success()
                            ->send();
                    }),
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

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with('latestSync');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListProviders::route('/'),
            'create' => Pages\CreateProvider::route('/create'),
            'edit' => Pages\EditProvider::route('/{record}/edit'),
        ];
    }
}
