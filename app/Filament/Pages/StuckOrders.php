<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Enums\OrderStatus;
use App\Enums\Permission;
use App\Filament\Resources\OrderResource;
use App\Filament\Support\AuthorizesWithPermission;
use App\Models\Order;
use Filament\Pages\Page;
use Filament\Tables\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Paid work that has not finished, oldest first.
 *
 * The queue an operator works through. An order sits here when a customer has
 * paid and has no server yet: either provisioning is still in flight, or it has
 * been parked for a person because something uncertain happened at the provider
 * and no automatic path may resolve it.
 *
 * Age is the column that matters. A `provisioning` order two minutes old is the
 * system working; the same order two hours old is somebody waiting for a server
 * they bought. Both actions here are the resource's own — the same reconcile
 * and retry, going through the same domain services, with the same reason and
 * audit entry.
 */
class StuckOrders extends Page implements HasTable
{
    use AuthorizesWithPermission;
    use InteractsWithTable;

    protected static ?string $navigationIcon = 'heroicon-o-queue-list';

    protected static ?string $navigationGroup = 'Operations';

    protected static ?int $navigationSort = 15;

    protected static ?string $title = 'Stuck orders';

    protected static string $view = 'filament.pages.table-page';

    public static function viewPermission(): Permission
    {
        return Permission::ProvisioningView;
    }

    public static function canAccess(): bool
    {
        return self::operatorMay(Permission::ProvisioningView);
    }

    /** A badge on the navigation, so nobody has to go looking. */
    public static function getNavigationBadge(): ?string
    {
        $count = self::baseQuery()->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => self::baseQuery()->with(['user', 'product']))
            ->columns([
                TextColumn::make('order_number')->label('Order')->searchable(),
                TextColumn::make('user.name')->label('Customer')->searchable(),
                TextColumn::make('product.name')->label('Product')->toggleable(),
                TextColumn::make('status')->badge(),
                TextColumn::make('created_at')
                    ->label('Age')
                    // Relative, because the question is always "how long has
                    // this customer been waiting".
                    ->since()
                    ->sortable(),
                TextColumn::make('attempts')->numeric(),
                TextColumn::make('failure_category')->label('Last error')->badge()->placeholder('—'),
                TextColumn::make('provisioning_uuid')->label('Token')->limit(12)->toggleable(),
            ])
            ->actions([
                Action::make('view')
                    ->label('Open')
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    ->url(fn (Order $record): string => OrderResource::getUrl('view', ['record' => $record])),
                OrderResource::reconcileAction(Action::class),
                OrderResource::retryAction(Action::class),
            ])
            ->bulkActions([])
            ->defaultSort('created_at')
            ->paginated([25, 50]);
    }

    /**
     * Paid, unfinished, and not settled one way or the other.
     */
    /**
     * @return Builder<Order>
     */
    private static function baseQuery(): Builder
    {
        return Order::query()->whereIn('status', [
            OrderStatus::Paid->value,
            OrderStatus::Provisioning->value,
            OrderStatus::NeedsAttention->value,
        ]);
    }
}
