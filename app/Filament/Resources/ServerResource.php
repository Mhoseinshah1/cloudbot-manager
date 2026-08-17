<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ServerResource\Pages;
use App\Models\Server;
use App\Models\ServerAction;
use App\Services\ServerActionService;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class ServerResource extends Resource
{
    protected static ?string $model = Server::class;

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Select::make('user_id')
                    ->relationship('user', 'name')
                    ->required(),
                Forms\Components\Select::make('order_id')
                    ->relationship('order', 'id'),
                Forms\Components\Select::make('product_id')
                    ->relationship('product', 'name'),
                Forms\Components\Select::make('provider_id')
                    ->relationship('provider', 'name')
                    ->required(),
                Forms\Components\TextInput::make('provider_server_id')
                    ->maxLength(255),
                Forms\Components\TextInput::make('name')
                    ->required()
                    ->maxLength(255),
                Forms\Components\TextInput::make('ip_address')
                    ->maxLength(255),
                Forms\Components\TextInput::make('provider_location_id')
                    ->numeric(),
                Forms\Components\TextInput::make('plan_snapshot'),
                Forms\Components\FileUpload::make('image_snapshot')
                    ->image(),
                Forms\Components\TextInput::make('status')
                    ->required()
                    ->maxLength(255)
                    ->default('pending'),
                Forms\Components\TextInput::make('power_state')
                    ->required()
                    ->maxLength(255)
                    ->default('off'),
                Forms\Components\TextInput::make('provider_cost')
                    ->numeric(),
                Forms\Components\TextInput::make('provider_currency')
                    ->maxLength(3),
                Forms\Components\TextInput::make('exchange_rate')
                    ->numeric(),
                Forms\Components\TextInput::make('local_cost')
                    ->numeric(),
                Forms\Components\TextInput::make('selling_price')
                    ->numeric(),
                Forms\Components\TextInput::make('gross_margin')
                    ->numeric(),
                Forms\Components\Textarea::make('root_password_encrypted')
                    ->columnSpanFull(),
                Forms\Components\DateTimePicker::make('expires_at'),
                Forms\Components\DateTimePicker::make('suspended_at'),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('user.name')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('order.id')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('product.name')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('provider.name')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('provider_server_id')
                    ->searchable(),
                Tables\Columns\TextColumn::make('name')
                    ->searchable(),
                Tables\Columns\TextColumn::make('ip_address')
                    ->searchable(),
                Tables\Columns\TextColumn::make('provider_location_id')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('status')
                    ->searchable(),
                Tables\Columns\TextColumn::make('power_state')
                    ->searchable(),
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
                Tables\Columns\TextColumn::make('selling_price')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('gross_margin')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('expires_at')
                    ->dateTime()
                    ->sortable(),
                Tables\Columns\TextColumn::make('suspended_at')
                    ->dateTime()
                    ->sortable(),
                Tables\Columns\TextColumn::make('deleted_at')
                    ->dateTime()
                    ->sortable()
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
                Tables\Actions\Action::make('powerOn')
                    ->label('Power On')
                    ->icon('heroicon-o-play')
                    ->color('success')
                    ->requiresConfirmation()
                    ->action(fn (Server $record) => self::runAction($record, ServerAction::ACTION_POWER_ON)),
                Tables\Actions\Action::make('powerOff')
                    ->label('Power Off')
                    ->icon('heroicon-o-stop')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->action(fn (Server $record) => self::runAction($record, ServerAction::ACTION_POWER_OFF)),
                Tables\Actions\Action::make('reboot')
                    ->label('Reboot')
                    ->icon('heroicon-o-arrow-path')
                    ->requiresConfirmation()
                    ->action(fn (Server $record) => self::runAction($record, ServerAction::ACTION_REBOOT)),
                Tables\Actions\Action::make('resetPassword')
                    ->label('Reset Password')
                    ->icon('heroicon-o-key')
                    ->requiresConfirmation()
                    ->action(function (Server $record): void {
                        self::runAction($record, ServerAction::ACTION_RESET_PASSWORD);
                    }),
                Tables\Actions\Action::make('delete')
                    ->label('Delete Server')
                    ->icon('heroicon-o-trash')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->action(fn (Server $record) => self::runAction($record, ServerAction::ACTION_DELETE)),
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

    private static function runAction(Server $record, string $action): void
    {
        try {
            $service = app(ServerActionService::class);
            $service->perform($record, $action, auth()->user());

            Notification::make()
                ->title(ucwords(str_replace('_', ' ', $action)))
                ->body("Server [{$record->name}] updated.")
                ->success()
                ->send();
        } catch (\Throwable $e) {
            Notification::make()
                ->title('Server action failed')
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListServers::route('/'),
            'create' => Pages\CreateServer::route('/create'),
            'edit' => Pages\EditServer::route('/{record}/edit'),
        ];
    }
}
