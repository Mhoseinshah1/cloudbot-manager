<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Enums\Permission;
use App\Enums\SubscriptionStatus;
use App\Filament\Resources\SubscriptionResource\Pages;
use App\Filament\Support\AuthorizesWithPermission;
use App\Models\Subscription;
use App\Models\User;
use App\Subscriptions\MonthlyLifecycleService;
use App\Subscriptions\SubscriptionRenewalService;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Throwable;

/**
 * Service periods, and the one financial action staff may take on one.
 *
 * `current_period_end` is the single authority on when a service expires, so it
 * is not a date picker. Neither is `status` a dropdown and nor `grace_until` a
 * field: those are reached by a renewal being paid for or a lifecycle sweep
 * finding a period has run out, and setting one by hand would give a customer
 * service nobody charged for or take away service they did pay for.
 *
 * The renewal action is an ordinary renewal, charged at the current price
 * through the Phase 11 service: the customer's wallet is debited, an invoice is
 * issued, the immutable renewal row is written and exactly one fixed period is
 * appended. There is deliberately no free-extension action — no domain policy
 * defines one, and inventing it here would be giving away service from a screen.
 */
class SubscriptionResource extends Resource
{
    use AuthorizesWithPermission;

    protected static ?string $model = Subscription::class;

    protected static ?string $navigationIcon = 'heroicon-o-arrow-path-rounded-square';

    protected static ?string $navigationGroup = 'Operations';

    protected static ?int $navigationSort = 35;

    public static function viewPermission(): Permission
    {
        return Permission::SubscriptionsView;
    }

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
            ->modifyQueryUsing(fn (Builder $query): Builder => $query
                ->with(['user', 'server']))
            ->columns([
                Tables\Columns\TextColumn::make('id')->label('ID')->sortable(),
                Tables\Columns\TextColumn::make('user.name')->label('Customer')->searchable(),
                Tables\Columns\TextColumn::make('server.name')->label('Server')->searchable()->placeholder('—'),
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->color(fn (SubscriptionStatus $state): string => match ($state) {
                        SubscriptionStatus::Active => 'success',
                        SubscriptionStatus::Grace => 'warning',
                        SubscriptionStatus::NeedsAttention => 'danger',
                        default => 'gray',
                    })
                    ->sortable(),
                Tables\Columns\TextColumn::make('price_toman')->label('Price')->numeric()->suffix(' T'),
                Tables\Columns\TextColumn::make('current_period_end')->label('Expires')->dateTime()->sortable(),
                Tables\Columns\TextColumn::make('grace_until')->label('Grace until')->dateTime()->placeholder('—'),
                Tables\Columns\TextColumn::make('billing_mode')->badge()->toggleable(),
                Tables\Columns\TextColumn::make('last_billed_at')->dateTime()->toggleable()->placeholder('—'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')->options(
                    collect(SubscriptionStatus::cases())
                        ->mapWithKeys(fn (SubscriptionStatus $c): array => [$c->value => $c->value])
                        ->all(),
                ),
                Tables\Filters\Filter::make('expiring')
                    ->label('Expiring within 3 days')
                    ->query(fn (Builder $query): Builder => $query
                        ->where('status', SubscriptionStatus::Active->value)
                        ->whereBetween('current_period_end', [now(), now()->addDays(3)])),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                self::renewAction(),
            ])
            ->bulkActions([])
            ->defaultSort('current_period_end');
    }

    /**
     * @return array<string, class-string>
     */
    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSubscriptions::route('/'),
            'view' => Pages\ViewSubscription::route('/{record}'),
        ];
    }

    /**
     * Renew on the customer's behalf, at the current price.
     *
     * Financially identical to the customer pressing renew in Telegram, because
     * it is the same service: same wallet debit, same invoice, same immutable
     * renewal identity, same fixed period appended to the old end. A wallet
     * that cannot cover it refuses, and nothing partial is written.
     */
    private static function renewAction(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('renew')
            ->label('Renew')
            ->icon('heroicon-o-arrow-path')
            ->color('primary')
            ->requiresConfirmation()
            ->modalHeading('Renew this subscription')
            ->modalDescription("Charges the customer's wallet at today's price and appends one period to the current end.")
            ->form([
                Placeholder::make('quote')
                    ->label('Current renewal')
                    ->content(function (Subscription $record): string {
                        $customer = User::query()->whereKey($record->user_id)->first();

                        if (! $customer instanceof User) {
                            return 'No customer record.';
                        }

                        try {
                            $quote = app(SubscriptionRenewalService::class)->quote($customer, $record);

                            return number_format($quote->priceToman).' Toman — new end '
                                .$quote->newPeriodEnd->format('Y-m-d H:i').' UTC';
                        } catch (Throwable $exception) {
                            return 'Cannot be renewed: '.$exception->getMessage();
                        }
                    }),
                Textarea::make('reason')->label('Reason')->required()->maxLength(500),
            ])
            ->visible(fn (Subscription $record): bool => self::operatorMay(Permission::SubscriptionsManage)
                && $record->status->isRenewable())
            ->action(function (Subscription $record): void {
                $customer = User::query()->whereKey($record->user_id)->first();

                if (! $customer instanceof User) {
                    return;
                }

                try {
                    // No approved price is passed: an operator confirms today's
                    // figure on the screen above, and the service takes its own
                    // fresh quote under the row lock regardless.
                    app(SubscriptionRenewalService::class)->renew($customer, $record);

                    $renewed = $record->fresh();

                    if ($renewed instanceof Subscription) {
                        // After the renewal commits, never inside it.
                        app(MonthlyLifecycleService::class)->requestPowerOnAfterRenewal($renewed);
                    }

                    Notification::make()->success()->title('Subscription renewed.')->send();
                } catch (Throwable $exception) {
                    Notification::make()->danger()->title('Renewal refused')
                        ->body($exception->getMessage())->send();
                }
            });
    }
}
