<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Enums\Permission;
use App\Filament\Resources\ExchangeRateResource\Pages;
use App\Filament\Support\AuthorizesWithPermission;
use App\Models\ExchangeRate;
use App\Models\User;
use App\Pricing\ExchangeRateService;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Throwable;

/**
 * Exchange rates: append a new one, never change an old one.
 *
 * Orders and invoices are priced against a specific row, so editing one does
 * not correct a rate — it silently restates what a customer was charged
 * against, with nothing in the record to show it happened. A rate changes by
 * recording the next one, which is also what the database enforces: the table
 * carries triggers that reject UPDATE and DELETE outright.
 *
 * Recording goes through `ExchangeRateService`, which checks the operator's
 * permission, writes the audit entry and takes the FX authority lock — a raw
 * model create here would skip all three.
 */
class ExchangeRateResource extends Resource
{
    use AuthorizesWithPermission;

    protected static ?string $model = ExchangeRate::class;

    protected static ?string $navigationIcon = 'heroicon-o-arrows-right-left';

    protected static ?string $navigationGroup = 'Billing';

    protected static ?int $navigationSort = 50;

    protected static ?string $navigationLabel = 'Exchange Rates';

    public static function viewPermission(): Permission
    {
        return Permission::SettingsView;
    }

    public static function managePermission(): ?Permission
    {
        return Permission::SettingsManage;
    }

    /** Appended through the service's action, not through a Filament form. */
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
            ->columns([
                Tables\Columns\TextColumn::make('id')->label('ID')->sortable(),
                Tables\Columns\TextColumn::make('currency')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('rate_to_toman')->label('Rate (Toman)'),
                Tables\Columns\TextColumn::make('source')->badge(),
                Tables\Columns\TextColumn::make('effective_from')->dateTime()->sortable(),
                Tables\Columns\TextColumn::make('created_at')->dateTime()->toggleable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('currency')
                    ->options(fn (): array => ExchangeRate::query()
                        ->distinct()
                        ->orderBy('currency')
                        ->pluck('currency', 'currency')
                        ->all()),
            ])
            ->actions([])
            ->bulkActions([])
            ->defaultSort('id', 'desc')
            ->headerActions([
                self::recordAction(),
            ]);
    }

    /**
     * @return array<string, \Filament\Resources\Pages\PageRegistration>
     */
    public static function getPages(): array
    {
        return [
            'index' => Pages\ListExchangeRates::route('/'),
        ];
    }

    private static function recordAction(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('recordRate')
            ->label('Record a rate')
            ->icon('heroicon-o-plus')
            ->requiresConfirmation()
            ->modalHeading('Record a new exchange rate')
            ->modalDescription('Appends a new row. Existing rates are history and are never rewritten.')
            ->form([
                TextInput::make('currency')
                    ->label('Currency')
                    ->required()
                    ->maxLength(3)
                    ->minLength(3)
                    ->helperText('Three-letter code, e.g. EUR.'),
                TextInput::make('rate_to_toman')
                    ->label('Toman per unit')
                    ->required()
                    // A string, validated as an exact decimal. Never a float:
                    // this number multiplies provider costs into real money.
                    ->rule('regex:/^\d+(\.\d{1,8})?$/')
                    ->helperText('Exact decimal, up to eight places.'),
            ])
            ->visible(fn (): bool => self::operatorMay(Permission::SettingsManage))
            ->action(function (array $data): void {
                $operator = self::operator();

                if (! $operator instanceof User) {
                    return;
                }

                try {
                    app(ExchangeRateService::class)->recordManualRate(
                        strtoupper(trim((string) $data['currency'])),
                        trim((string) $data['rate_to_toman']),
                        $operator,
                    );

                    Notification::make()->success()->title('Rate recorded.')->send();
                } catch (Throwable $exception) {
                    Notification::make()->danger()->title('Refused')->body($exception->getMessage())->send();
                }
            });
    }
}
