<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Admin\OrphanLinkService;
use App\Enums\OrderStatus;
use App\Enums\Permission;
use App\Enums\ServerStatus;
use App\Filament\Support\AuthorizesWithPermission;
use App\Models\Order;
use App\Models\OutboxMessage;
use App\Models\Provider;
use App\Models\Server;
use App\Models\User;
use App\Outbox\OutboxTopic;
use App\Provisioning\InventoryReconciler;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Where what a provider holds and what this system believes disagree.
 *
 * Every row here costs money in one direction or the other: an orphan is a
 * machine the provider bills us for that no customer paid for, and a missing
 * server is a machine a customer is paying for that nobody can find.
 *
 * The findings are read from the discrepancies the inventory reconciler already
 * recorded, rather than by calling providers from a page render — a dashboard
 * that made network calls would be slow when it mattered most and would hammer
 * a provider that was already struggling. Reconciling on demand is an explicit
 * action.
 *
 * Linking an orphan to an order is the one genuinely dangerous tool in the
 * panel. It goes through `OrphanLinkService`, which re-reads the machine from
 * the provider, refuses a token that belongs to someone else, refuses an order
 * that already has a server, and takes ownership from the order rather than
 * from anything typed here. Ambiguity is refused rather than guessed.
 */
class InventoryDiscrepancies extends Page
{
    use AuthorizesWithPermission;

    protected static ?string $navigationIcon = 'heroicon-o-scale';

    protected static ?string $navigationGroup = 'Operations';

    protected static ?int $navigationSort = 50;

    protected static ?string $title = 'Inventory';

    protected static string $view = 'filament.pages.inventory-discrepancies';

    public static function viewPermission(): Permission
    {
        return Permission::InventoryView;
    }

    public static function canAccess(): bool
    {
        return self::operatorMay(Permission::InventoryView);
    }

    /**
     * Discrepancies the reconciler recorded, newest first.
     *
     * @return Collection<int, OutboxMessage>
     */
    public function discrepancies(): Collection
    {
        return OutboxMessage::query()
            ->where('topic', OutboxTopic::InventoryDiscrepancy)
            ->latest('id')
            ->limit(100)
            ->get();
    }

    /**
     * Servers this system believes in that a complete read could not find.
     *
     * @return Collection<int, Server>
     */
    public function missingServers(): Collection
    {
        return Server::query()
            ->whereIn('status', [ServerStatus::Missing->value, ServerStatus::NeedsAttention->value])
            ->with(['user', 'provider'])
            ->latest('updated_at')
            ->limit(100)
            ->get();
    }

    /**
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            $this->reconcileAction(),
            $this->linkOrphanAction(),
        ];
    }

    /** Run the existing inventory reconciliation for one provider. */
    private function reconcileAction(): Action
    {
        return Action::make('reconcileInventory')
            ->label('Reconcile a provider')
            ->icon('heroicon-o-arrow-path')
            ->requiresConfirmation()
            ->modalDescription('Reads the provider inventory and records what disagrees. Nothing remote is changed and no orphan is ever deleted.')
            ->form([
                Select::make('provider_id')
                    ->label('Provider')
                    ->options(fn (): array => Provider::query()->orderBy('code')->pluck('code', 'id')->all())
                    ->required(),
            ])
            ->visible(fn (): bool => self::operatorMay(Permission::InventoryManage))
            ->action(function (array $data): void {
                $provider = Provider::query()->whereKey($data['provider_id'])->first();

                if (! $provider instanceof Provider) {
                    return;
                }

                try {
                    $report = app(InventoryReconciler::class)->reconcile($provider);

                    if (! $report->succeeded()) {
                        // A failed read is never an empty inventory, and the
                        // reconciler does not treat it as one.
                        Notification::make()->danger()->title('Inventory could not be read')
                            ->body((string) $report->failure)->send();

                        return;
                    }

                    Notification::make()->success()->title('Reconciled '.$provider->code)->body(sprintf(
                        '%d checked, %d drifted, %d missing, %d orphans.',
                        $report->localChecked,
                        $report->drifted,
                        $report->missing,
                        $report->orphans,
                    ))->send();
                } catch (Throwable $exception) {
                    Notification::make()->danger()->title('Reconcile failed')
                        ->body($exception->getMessage())->send();
                }
            });
    }

    /**
     * Attach an unexplained remote machine to the order that paid for it.
     *
     * The form collects a provider, an identifier and an order; every claim in
     * it is re-established against the provider and the database before
     * anything is written, and a mismatch refuses rather than proceeds.
     */
    private function linkOrphanAction(): Action
    {
        return Action::make('linkOrphan')
            ->label('Link an orphan to an order')
            ->icon('heroicon-o-link')
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading('Link a provider server to an order')
            ->modalDescription('The machine is read from the provider and checked against the order before anything is written. A token belonging to another order is refused.')
            ->form([
                Select::make('provider_id')
                    ->label('Provider')
                    ->options(fn (): array => Provider::query()->orderBy('code')->pluck('code', 'id')->all())
                    ->required()
                    ->live(),
                \Filament\Forms\Components\TextInput::make('provider_server_id')
                    ->label('Provider server ID')
                    ->required()
                    ->maxLength(120),
                Select::make('order_id')
                    ->label('Order')
                    // Only orders that could receive a server: paid, unfinished
                    // and without one already.
                    ->options(fn (): array => Order::query()
                        ->whereIn('status', [
                            OrderStatus::Paid->value,
                            OrderStatus::Provisioning->value,
                            OrderStatus::NeedsAttention->value,
                        ])
                        ->whereDoesntHave('server')
                        ->orderByDesc('id')
                        ->limit(200)
                        ->pluck('order_number', 'id')
                        ->all())
                    ->required()
                    ->searchable(),
                Textarea::make('reason')->label('Reason')->required()->maxLength(500),
            ])
            ->visible(fn (): bool => self::operatorMay(Permission::InventoryManage))
            ->action(function (array $data): void {
                $operator = self::operator();
                $provider = Provider::query()->whereKey($data['provider_id'])->first();
                $order = Order::query()->whereKey($data['order_id'])->first();

                if (! $operator instanceof User || ! $provider instanceof Provider || ! $order instanceof Order) {
                    return;
                }

                try {
                    $server = app(OrphanLinkService::class)->link(
                        $provider,
                        trim((string) $data['provider_server_id']),
                        $order,
                        $operator,
                        (string) $data['reason'],
                    );

                    Notification::make()->success()->title('Linked.')
                        ->body('Server #'.$server->getKey().' now belongs to '.$order->order_number)->send();
                } catch (Throwable $exception) {
                    // Refusals here are the feature working. The message names
                    // the invariant that would have been broken.
                    Notification::make()->danger()->title('Refused')
                        ->body($exception->getMessage())->send();
                }
            });
    }
}
