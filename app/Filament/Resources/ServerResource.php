<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Enums\Permission;
use App\Enums\ServerActionType;
use App\Enums\ServerStatus;
use App\Filament\Resources\ServerResource\Pages;
use App\Filament\Support\AuthorizesWithPermission;
use App\Models\Server;
use App\Models\User;
use App\Servers\ServerActionService;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Throwable;

/**
 * The machines customers have, and the operations staff may ask for.
 *
 * Every action records a `ServerAction` through the existing service and
 * returns; the provider call happens on the provisioning worker, behind the
 * server's lock and the durable attempt budget. Nothing here talks to a
 * provider from a web request, and nothing here settles an action by hand.
 *
 * The root password is not on this screen, in any form. It exists in one
 * encrypted column and reaches a person only through the owner-only reveal
 * flow, which audits the reveal and deletes the message afterwards.
 */
class ServerResource extends Resource
{
    use AuthorizesWithPermission;

    protected static ?string $model = Server::class;

    protected static ?string $navigationIcon = 'heroicon-o-server';

    protected static ?string $navigationGroup = 'Operations';

    protected static ?int $navigationSort = 30;

    public static function viewPermission(): Permission
    {
        return Permission::ServersView;
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
                ->with(['user', 'provider', 'subscription']))
            ->columns([
                Tables\Columns\TextColumn::make('id')->label('ID')->sortable(),
                Tables\Columns\TextColumn::make('name')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('user.name')->label('Customer')->searchable(),
                Tables\Columns\TextColumn::make('provider.code')->label('Provider')->sortable(),
                Tables\Columns\TextColumn::make('provider_server_id')->label('Provider ID')->searchable()->toggleable(),
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->color(fn (ServerStatus $state): string => match ($state) {
                        ServerStatus::Active => 'success',
                        ServerStatus::Missing, ServerStatus::NeedsAttention => 'danger',
                        ServerStatus::Suspended => 'warning',
                        ServerStatus::Terminated => 'gray',
                    })
                    ->sortable(),
                Tables\Columns\TextColumn::make('power_state')->label('Power')->badge(),
                Tables\Columns\TextColumn::make('ip_address')->label('IPv4')->searchable()->placeholder('—'),
                Tables\Columns\TextColumn::make('subscription.current_period_end')
                    ->label('Expires')->dateTime()->placeholder('—')->toggleable(),
                Tables\Columns\TextColumn::make('created_at')->dateTime()->toggleable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')->options(
                    collect(ServerStatus::cases())
                        ->mapWithKeys(fn (ServerStatus $c): array => [$c->value => $c->value])
                        ->all(),
                ),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                self::operationAction('powerOn', ServerActionType::PowerOn, 'Power on', 'heroicon-o-play', 'success'),
                self::operationAction('powerOff', ServerActionType::PowerOff, 'Power off', 'heroicon-o-stop', 'warning'),
                self::operationAction('reboot', ServerActionType::Reboot, 'Reboot', 'heroicon-o-arrow-path', 'warning'),
                self::operationAction('delete', ServerActionType::Delete, 'Delete', 'heroicon-o-trash', 'danger'),
            ])
            ->bulkActions([])
            ->defaultSort('id', 'desc');
    }

    /**
     * @return array<string, \Filament\Resources\Pages\PageRegistration>
     */
    public static function getPages(): array
    {
        return [
            'index' => Pages\ListServers::route('/'),
            'view' => Pages\ViewServer::route('/{record}'),
        ];
    }

    /**
     * One operational request against a machine.
     *
     * The idempotency key is built from the operator, the server, the action
     * and the minute, so a double-clicked button is one operation while a
     * deliberate second request a minute later is genuinely a second one.
     */
    private static function operationAction(
        string $name,
        ServerActionType $action,
        string $label,
        string $icon,
        string $colour,
    ): Tables\Actions\Action {
        return Tables\Actions\Action::make($name)
            ->label($label)
            ->icon($icon)
            ->color($colour)
            ->requiresConfirmation()
            ->modalHeading($label.' this server')
            ->modalDescription($action === ServerActionType::Delete
                ? 'The machine and everything on it are destroyed at the provider. This cannot be undone.'
                : 'Recorded as a server action and carried out by the provisioning worker.')
            ->form([
                Textarea::make('reason')->label('Reason')->required()->maxLength(500),
            ])
            ->visible(fn (Server $record): bool => self::operatorMay(Permission::ServersManage)
                && $record->status !== ServerStatus::Terminated)
            ->action(function (Server $record, array $data) use ($action, $label): void {
                $operator = self::operator();

                if (! $operator instanceof User) {
                    return;
                }

                $owner = User::query()->whereKey($record->user_id)->first();

                if (! $owner instanceof User) {
                    Notification::make()->danger()->title('That server has no owner record.')->send();

                    return;
                }

                try {
                    // Through the existing service, which checks the provider
                    // offers the capability and writes the intent inside the
                    // same transaction as the audit entry.
                    app(ServerActionService::class)->request(
                        $owner,
                        (int) $record->getKey(),
                        $action,
                        sprintf(
                            'admin:%d:server:%d:%s:%s',
                            $operator->getKey(),
                            $record->getKey(),
                            $action->value,
                            now()->format('YmdHi'),
                        ),
                        [
                            'requested_by_admin_id' => (int) $operator->getKey(),
                            'reason' => mb_substr(trim((string) $data['reason']), 0, 500),
                        ],
                    );

                    Notification::make()->success()->title($label.' requested.')->send();
                } catch (Throwable $exception) {
                    Notification::make()->danger()->title('Refused')->body($exception->getMessage())->send();
                }
            });
    }
}
