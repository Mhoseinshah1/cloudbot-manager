<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Audit\AuditEvent;
use App\Audit\AuditRecorder;
use App\Enums\OrderStatus;
use App\Enums\Permission;
use App\Filament\Resources\OrderResource\Pages;
use App\Filament\Support\AuthorizesWithPermission;
use App\Jobs\ProvisionOrderJob;
use App\Models\Order;
use App\Models\User;
use App\Enums\ConfirmedNoServerOutcome;
use App\Orders\RefundService;
use App\Provisioning\ReconciliationService;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Throwable;

/**
 * Orders, and the three things an operator may do about one that went wrong.
 *
 * There is no status dropdown, and that absence is the design. An order's state
 * is reached by something actually happening — money arriving, a provider
 * answering, a reconciler establishing a fact — and a status set by hand is a
 * claim with nothing behind it. What the panel offers instead is the ability to
 * ask the domain to look again (reconcile), to try again (retry), or to give
 * the money back when failure is confirmed (refund).
 */
class OrderResource extends Resource
{
    use AuthorizesWithPermission;

    protected static ?string $model = Order::class;

    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-list';

    protected static ?string $navigationGroup = 'Operations';

    protected static ?int $navigationSort = 10;

    public static function viewPermission(): Permission
    {
        return Permission::OrdersView;
    }

    /** No generic edit: every transition belongs to a domain service. */
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
                ->with(['user', 'product', 'server']))
            ->columns([
                Tables\Columns\TextColumn::make('order_number')->label('Order')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('user.name')->label('Customer')->searchable(),
                Tables\Columns\TextColumn::make('product.name')->label('Product')->toggleable(),
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->color(fn (OrderStatus $state): string => match ($state) {
                        OrderStatus::Provisioned => 'success',
                        OrderStatus::Failed, OrderStatus::Cancelled, OrderStatus::Expired => 'gray',
                        OrderStatus::Refunded => 'info',
                        OrderStatus::NeedsAttention => 'danger',
                        default => 'warning',
                    })
                    ->sortable(),
                Tables\Columns\TextColumn::make('total_toman')->label('Amount')->numeric()->sortable()->suffix(' T'),
                Tables\Columns\TextColumn::make('attempts')->numeric()->toggleable(),
                Tables\Columns\TextColumn::make('server.name')->label('Server')->placeholder('—')->toggleable(),
                Tables\Columns\TextColumn::make('created_at')->dateTime()->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')->options(
                    collect(OrderStatus::cases())
                        ->mapWithKeys(fn (OrderStatus $c): array => [$c->value => $c->value])
                        ->all(),
                ),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                self::reconcileAction(),
                self::retryAction(),
                self::refundAction(),
            ])
            ->bulkActions([])
            ->defaultSort('id', 'desc');
    }

    /**
     * @return array<string, class-string>
     */
    public static function getPages(): array
    {
        return [
            'index' => Pages\ListOrders::route('/'),
            'view' => Pages\ViewOrder::route('/{record}'),
        ];
    }

    /**
     * Ask the reconciler what actually happened at the provider.
     *
     * This is the safe operational action: it establishes facts rather than
     * asserting them, and the same code the scheduled sweep runs. It can settle
     * an order as provisioned or park it, and never marks one successful on an
     * operator's say-so.
     */
    public static function reconcileAction(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('forceReconcile')
            ->label('Force reconcile')
            ->icon('heroicon-o-arrow-path')
            ->color('warning')
            ->requiresConfirmation()
            ->modalHeading('Reconcile this order against its provider')
            ->modalDescription('Asks the provider what exists and settles from the answer. Nothing is marked successful by hand.')
            ->form([
                Textarea::make('reason')->label('Reason')->required()->maxLength(500),
            ])
            ->visible(fn (Order $record): bool => self::operatorMay(Permission::ProvisioningManage)
                && ! $record->status->isTerminal())
            ->action(function (Order $record, array $data): void {
                $operator = self::operator();

                if (! $operator instanceof User) {
                    return;
                }

                app(AuditRecorder::class)->record(
                    AuditEvent::OrderReconcileRequested,
                    actor: $operator,
                    subject: $record,
                    metadata: [
                        'order_id' => $record->getKey(),
                        'order_number' => $record->order_number,
                        'status' => $record->status->value,
                        'reason' => mb_substr(trim((string) $data['reason']), 0, 500),
                    ],
                );

                try {
                    // The existing reconciliation path, which owns the provider
                    // reads and the state decisions. No second reconciler.
                    $result = app(ReconciliationService::class)->reconcile($record);

                    Notification::make()->success()
                        ->title('Reconciled.')->body('Outcome: '.$result->state)->send();
                } catch (Throwable $exception) {
                    Notification::make()->danger()->title('Reconcile failed')
                        ->body($exception->getMessage())->send();
                }
            });
    }

    /**
     * Queue the existing provisioning work again.
     *
     * Dispatches the ordinary job rather than calling a provider from a web
     * request. Nothing is reset: the durable attempt budget still applies, and
     * the order keeps the provisioning token it committed to before its first
     * create — a new token would be a second machine.
     */
    public static function retryAction(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('retryProvisioning')
            ->label('Retry provisioning')
            ->icon('heroicon-o-play')
            ->color('primary')
            ->requiresConfirmation()
            ->modalHeading('Retry provisioning for this order')
            ->modalDescription('Queues the existing job. Attempt limits are unchanged and the provisioning token is reused.')
            ->form([
                Textarea::make('reason')->label('Reason')->required()->maxLength(500),
            ])
            ->visible(fn (Order $record): bool => self::operatorMay(Permission::ProvisioningManage)
                // Only states where more provisioning work is meaningful. A
                // provisioned or refunded order has nothing left to try.
                && in_array($record->status, [
                    OrderStatus::Paid,
                    OrderStatus::Provisioning,
                    OrderStatus::NeedsAttention,
                ], true))
            ->action(function (Order $record, array $data): void {
                $operator = self::operator();

                if (! $operator instanceof User) {
                    return;
                }

                app(AuditRecorder::class)->record(
                    AuditEvent::ProvisioningRetryRequested,
                    actor: $operator,
                    subject: $record,
                    metadata: [
                        'order_id' => $record->getKey(),
                        'order_number' => $record->order_number,
                        'status' => $record->status->value,
                        'attempts' => $record->attempts,
                        'reason' => mb_substr(trim((string) $data['reason']), 0, 500),
                    ],
                );

                ProvisionOrderJob::dispatch((int) $record->getKey());

                Notification::make()->success()->title('Provisioning queued.')->send();
            });
    }

    /**
     * Return the money, when failure is confirmed.
     *
     * Through `RefundService`, which decides eligibility, finds the debit that
     * actually took the money and keys the credit so a replay cannot pay twice.
     * The operator states which confirmed outcome they are asserting — there is
     * deliberately no option meaning "probably", because an uncertain provider
     * state must be reconciled before anybody is refunded.
     */
    public static function refundAction(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('refund')
            ->label('Refund')
            ->icon('heroicon-o-receipt-refund')
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading('Refund this order in full')
            ->modalDescription('Only for a confirmed failure with no server. If the provider state is uncertain, reconcile first.')
            ->form([
                Select::make('outcome')
                    ->label('What is confirmed to have happened')
                    ->options(collect(ConfirmedNoServerOutcome::cases())
                        ->mapWithKeys(fn (ConfirmedNoServerOutcome $c): array => [$c->value => $c->value])
                        ->all())
                    ->required()
                    ->helperText('Every option here means "no server exists". Nothing here means "probably".'),
                Placeholder::make('amount')
                    ->label('Amount')
                    ->content(fn (Order $record): string => number_format((int) $record->total_toman).' Toman'),
                Textarea::make('reason')->label('Reason')->required()->maxLength(500),
            ])
            ->visible(fn (Order $record): bool => self::operatorMay(Permission::RefundsManage)
                && $record->status->isFunded()
                && $record->status !== OrderStatus::Provisioned)
            ->action(function (Order $record, array $data): void {
                try {
                    app(RefundService::class)->refundConfirmedFailure(
                        $record,
                        ConfirmedNoServerOutcome::from((string) $data['outcome']),
                        mb_substr(trim((string) $data['reason']), 0, 500),
                    );

                    Notification::make()->success()->title('Refunded to wallet.')->send();
                } catch (Throwable $exception) {
                    Notification::make()->danger()->title('Refund refused')
                        ->body($exception->getMessage())->send();
                }
            });
    }
}
