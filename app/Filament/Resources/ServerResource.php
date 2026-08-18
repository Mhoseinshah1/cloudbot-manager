<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ServerResource\Pages;
use App\Filament\Resources\ServerResource\RelationManagers\ServerBillingPeriodRelationManager;
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
                self::snapshotTextarea('plan_snapshot'),
                self::snapshotTextarea('image_snapshot'),
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

    /**
     * Snapshot metadata is stored as JSON arrays (provider plan/image data at
     * provisioning time). It is read-only in the admin form — never a file
     * upload — and formatted as pretty JSON for inspection.
     */
    private static function snapshotTextarea(string $name): Forms\Components\Textarea
    {
        return Forms\Components\Textarea::make($name)
            ->disabled()
            ->dehydrated(false)
            ->rows(4)
            ->formatStateUsing(static fn ($state): ?string => is_array($state)
                ? json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
                : $state);
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
                Tables\Columns\TextColumn::make('billing_mode')
                    ->badge()
                    ->searchable(),
                Tables\Columns\TextColumn::make('billing_state')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'active' => 'success',
                        'low_balance' => 'warning',
                        'payment_due' => 'warning',
                        'grace' => 'danger',
                        default => 'gray',
                    })
                    ->searchable(),
                Tables\Columns\TextColumn::make('hourly_rate_toman')
                    ->numeric()
                    ->sortable()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('monthly_cap_toman')
                    ->numeric()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('billing_started_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('last_billed_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('billing_period_started_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('billing_period_ends_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('current_period_charged')
                    ->numeric()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('grace_ends_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('billing_stopped_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
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
            ServerBillingPeriodRelationManager::class,
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
