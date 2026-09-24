<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Audit\AuditEvent;
use App\Audit\AuditRecorder;
use App\Enums\Permission;
use App\Filament\Resources\ProviderResource\Pages;
use App\Filament\Support\AuthorizesWithPermission;
use App\Models\Provider;
use App\Models\User;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * The cloud providers this installation can buy from.
 *
 * Enabling and disabling is the kill switch for one provider, and it is local
 * only: disabling stops new provisioning through that provider and changes
 * nothing about the machines it is already running. It does not touch remote
 * servers, does not clear credentials, and does not remove catalog or history —
 * an operator reaching for this during an incident needs a switch, not a
 * demolition.
 */
class ProviderResource extends Resource
{
    use AuthorizesWithPermission;

    protected static ?string $model = Provider::class;

    protected static ?string $navigationIcon = 'heroicon-o-server-stack';

    protected static ?string $navigationGroup = 'Providers';

    protected static ?int $navigationSort = 10;

    public static function viewPermission(): Permission
    {
        return Permission::ProvidersView;
    }

    public static function managePermission(): ?Permission
    {
        return Permission::ProvidersManage;
    }

    /** A provider row exists because the registry names an implementation. */
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
                ->withCount(['locations', 'plans', 'images', 'servers']))
            ->columns([
                Tables\Columns\TextColumn::make('code')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('name')->searchable(),
                Tables\Columns\IconColumn::make('enabled')->boolean()->sortable(),
                Tables\Columns\IconColumn::make('has_credential')
                    ->label('Credential')
                    ->boolean()
                    // Presence only. The token itself is never read here.
                    ->state(fn (Provider $record): bool => $record->credentials()->where('is_active', true)->exists()),
                Tables\Columns\TextColumn::make('locations_count')->label('Locations')->numeric(),
                Tables\Columns\TextColumn::make('plans_count')->label('Plans')->numeric(),
                Tables\Columns\TextColumn::make('images_count')->label('Images')->numeric(),
                Tables\Columns\TextColumn::make('servers_count')->label('Servers')->numeric(),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('enabled'),
            ])
            ->actions([
                self::availabilityAction(),
            ])
            ->bulkActions([])
            ->defaultSort('code');
    }

    /**
     * @return array<string, \Filament\Resources\Pages\PageRegistration>
     */
    public static function getPages(): array
    {
        return [
            'index' => Pages\ListProviders::route('/'),
        ];
    }

    /**
     * Turn one provider on or off.
     *
     * Owner-level (`ProvidersManage`), confirmed, and audited with the reason —
     * disabling a provider stops sales through it, and six weeks later somebody
     * will need to know who decided that and why.
     */
    private static function availabilityAction(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('toggleAvailability')
            ->label(fn (Provider $record): string => $record->enabled ? 'Disable' : 'Enable')
            ->icon(fn (Provider $record): string => $record->enabled ? 'heroicon-o-pause-circle' : 'heroicon-o-play-circle')
            ->color(fn (Provider $record): string => $record->enabled ? 'danger' : 'success')
            ->requiresConfirmation()
            ->modalHeading(fn (Provider $record): string => $record->enabled
                ? 'Disable this provider'
                : 'Enable this provider')
            ->modalDescription('Local only. No remote server is touched, no credential is cleared, and no history is removed.')
            ->form([
                Textarea::make('reason')->label('Reason')->required()->maxLength(500),
            ])
            ->visible(fn (): bool => self::operatorMay(Permission::ProvidersManage))
            ->action(function (Provider $record, array $data): void {
                $operator = self::operator();

                if (! $operator instanceof User) {
                    return;
                }

                $before = (bool) $record->enabled;
                $record->forceFill(['enabled' => ! $before])->save();

                app(AuditRecorder::class)->record(
                    AuditEvent::ProviderAvailabilityChanged,
                    actor: $operator,
                    subject: $record,
                    before: ['enabled' => $before],
                    after: ['enabled' => ! $before],
                    metadata: [
                        'provider_id' => $record->getKey(),
                        'provider_code' => $record->code,
                        'reason' => mb_substr(trim((string) $data['reason']), 0, 500),
                    ],
                );

                Notification::make()->success()
                    ->title($before ? 'Provider disabled.' : 'Provider enabled.')->send();
            });
    }
}
