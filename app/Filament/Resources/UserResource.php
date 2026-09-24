<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Admin\CustomerAccountService;
use App\Enums\Permission;
use App\Enums\UserStatus;
use App\Filament\Resources\UserResource\Pages;
use App\Filament\Support\AuthorizesWithPermission;
use App\Models\User;
use App\Wallet\WalletService;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Throwable;

/**
 * Customers, and the few things an operator may do to one.
 *
 * Status is changed through actions rather than a form field, because each
 * change is a domain operation with a reason and an audit entry behind it. The
 * wallet balance is shown and never editable: money moves through
 * `WalletService` or it does not move, and a number typed into a form would be
 * a balance with no ledger row explaining it.
 */
class UserResource extends Resource
{
    use AuthorizesWithPermission;

    protected static ?string $model = User::class;

    protected static ?string $navigationIcon = 'heroicon-o-users';

    protected static ?string $navigationGroup = 'Customers';

    protected static ?int $navigationSort = 10;

    public static function viewPermission(): Permission
    {
        return Permission::CustomersView;
    }

    public static function managePermission(): ?Permission
    {
        return Permission::CustomersManage;
    }

    /** Status is an action, never a form field. Nothing here is editable. */
    public static function canEdit(mixed $record): bool
    {
        return false;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('telegramAccounts'))
            ->columns([
                Tables\Columns\TextColumn::make('id')->sortable()->label('ID'),
                Tables\Columns\TextColumn::make('name')->searchable()->limit(30),
                Tables\Columns\TextColumn::make('telegramAccounts.username')
                    ->label('Telegram')
                    ->searchable()
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('telegramAccounts.telegram_user_id')
                    ->label('Telegram ID')
                    ->searchable()
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->color(fn (UserStatus $state): string => match ($state) {
                        UserStatus::Active => 'success',
                        UserStatus::Suspended => 'warning',
                        UserStatus::Banned => 'danger',
                    })
                    ->sortable(),
                Tables\Columns\TextColumn::make('wallet_balance_toman')
                    ->label('Wallet')
                    ->numeric()
                    ->sortable()
                    ->suffix(' T'),
                Tables\Columns\TextColumn::make('created_via')->label('Source')->toggleable(),
                Tables\Columns\TextColumn::make('created_at')->dateTime()->sortable()->toggleable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')->options(
                    collect(UserStatus::cases())
                        ->mapWithKeys(fn (UserStatus $case): array => [$case->value => $case->value])
                        ->all(),
                ),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                self::suspendAction(),
                self::banAction(),
                self::reactivateAction(),
                self::walletAdjustmentAction(),
            ])
            // No bulk actions at all. There is no operational case for
            // suspending forty customers in one click, and a mis-click that
            // could is a blast radius nobody needs.
            ->bulkActions([])
            ->defaultSort('id', 'desc');
    }

    /**
     * @return array<string, class-string>
     */
    public static function getPages(): array
    {
        return [
            'index' => Pages\ListUsers::route('/'),
            'view' => Pages\ViewUser::route('/{record}'),
        ];
    }

    private static function suspendAction(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('suspend')
            ->icon('heroicon-o-pause-circle')
            ->color('warning')
            ->requiresConfirmation()
            ->modalHeading('Suspend this customer')
            ->modalDescription('They keep their servers and their balance, and cannot buy, renew or operate anything.')
            ->form([
                Textarea::make('reason')
                    ->label('Reason')
                    ->required()
                    ->maxLength(500)
                    ->helperText('Recorded in the audit log. Whoever answers this customer later will read it.'),
            ])
            ->visible(fn (User $record): bool => self::operatorMay(Permission::CustomersManage)
                && $record->status !== UserStatus::Suspended)
            ->action(function (User $record, array $data): void {
                self::run(fn (CustomerAccountService $service, User $operator) => $service->suspend(
                    $record, $operator, (string) $data['reason'],
                ), 'Customer suspended.');
            });
    }

    private static function banAction(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('ban')
            ->icon('heroicon-o-no-symbol')
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading('Ban this customer')
            ->modalDescription('They lose access entirely. Their servers, orders and ledger are kept.')
            ->form([
                Textarea::make('reason')->label('Reason')->required()->maxLength(500),
            ])
            ->visible(fn (User $record): bool => self::operatorMay(Permission::CustomersManage)
                && $record->status !== UserStatus::Banned)
            ->action(function (User $record, array $data): void {
                self::run(fn (CustomerAccountService $service, User $operator) => $service->ban(
                    $record, $operator, (string) $data['reason'],
                ), 'Customer banned.');
            });
    }

    private static function reactivateAction(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('reactivate')
            ->icon('heroicon-o-play-circle')
            ->color('success')
            ->requiresConfirmation()
            ->form([
                Textarea::make('reason')->label('Note')->maxLength(500),
            ])
            ->visible(fn (User $record): bool => self::operatorMay(Permission::CustomersManage)
                && $record->status !== UserStatus::Active)
            ->action(function (User $record, array $data): void {
                self::run(fn (CustomerAccountService $service, User $operator) => $service->reactivate(
                    $record, $operator, $data['reason'] ?? null,
                ), 'Customer reactivated.');
            });
    }

    /**
     * A manual wallet movement, through the one authority that may make one.
     *
     * The direction is explicit rather than inferred from a sign, because a
     * stray minus in a text field is not how a customer should lose money. The
     * reference becomes the idempotency key, so a double-submitted form credits
     * once.
     */
    private static function walletAdjustmentAction(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('adjustWallet')
            ->label('Adjust wallet')
            ->icon('heroicon-o-banknotes')
            ->color('primary')
            ->requiresConfirmation()
            ->modalHeading('Adjust this wallet')
            ->modalDescription('Writes an immutable ledger row through WalletService. A debit can never overdraw.')
            ->form([
                Radio::make('direction')
                    ->options(['credit' => 'Credit (give money)', 'debit' => 'Debit (take money)'])
                    ->required()
                    ->inline(),
                TextInput::make('amount_toman')
                    ->label('Amount (Toman)')
                    ->numeric()
                    ->minValue(1)
                    ->required()
                    ->helperText('Whole Toman.'),
                TextInput::make('reference')
                    ->label('Reference')
                    ->required()
                    ->maxLength(120)
                    ->helperText('Your own reference. Becomes the idempotency key, so re-submitting cannot double it.'),
                Textarea::make('reason')->label('Reason')->required()->maxLength(500),
            ])
            ->visible(fn (): bool => self::operatorMay(Permission::WalletAdjust))
            ->action(function (User $record, array $data): void {
                $operator = self::operator();

                if (! $operator instanceof User) {
                    return;
                }

                $amount = (int) $data['amount_toman'];
                $signed = $data['direction'] === 'debit' ? -$amount : $amount;

                try {
                    app(WalletService::class)->adjust(
                        $record,
                        $signed,
                        'admin:adjust:'.$record->getKey().':'.trim((string) $data['reference']),
                        (string) $data['reason'],
                        $operator,
                        ['operator_id' => $operator->getKey(), 'reference' => trim((string) $data['reference'])],
                    );

                    Notification::make()->success()->title('Wallet adjusted.')->send();
                } catch (Throwable $exception) {
                    Notification::make()->danger()->title('Adjustment refused')
                        ->body($exception->getMessage())->send();
                }
            });
    }

    /**
     * Run one account action and report what happened.
     *
     * @param  callable(CustomerAccountService, User): mixed  $work
     */
    private static function run(callable $work, string $success): void
    {
        $operator = self::operator();

        if (! $operator instanceof User) {
            return;
        }

        try {
            $work(app(CustomerAccountService::class), $operator);

            Notification::make()->success()->title($success)->send();
        } catch (Throwable $exception) {
            Notification::make()->danger()->title('Refused')->body($exception->getMessage())->send();
        }
    }
}
