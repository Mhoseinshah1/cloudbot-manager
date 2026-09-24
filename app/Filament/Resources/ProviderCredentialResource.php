<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Audit\AuditEvent;
use App\Audit\AuditRecorder;
use App\Enums\Permission;
use App\Filament\Resources\ProviderCredentialResource\Pages;
use App\Filament\Support\AuthorizesWithPermission;
use App\Models\Provider;
use App\Models\ProviderCredential;
use App\Models\User;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Provider API credentials, which this screen can set and never show.
 *
 * There is no reveal action and no view page, deliberately. A token that can be
 * displayed is a token that ends up in a screenshot, a support thread or a
 * browser's autofill, and the operational need it would serve — "is the
 * credential there?" — is answered by the active column without showing
 * anything. A lost token is replaced, not recovered.
 *
 * The table carries only safe facts: which provider, whether a credential is
 * active, and when it last changed. The encrypted payload is never selected
 * into a column, an infolist or a form default.
 */
class ProviderCredentialResource extends Resource
{
    use AuthorizesWithPermission;

    protected static ?string $model = ProviderCredential::class;

    protected static ?string $navigationIcon = 'heroicon-o-key';

    protected static ?string $navigationGroup = 'Providers';

    protected static ?int $navigationSort = 20;

    protected static ?string $navigationLabel = 'Provider Credentials';

    public static function viewPermission(): Permission
    {
        return Permission::ProviderCredentialsManage;
    }

    public static function managePermission(): ?Permission
    {
        return Permission::ProviderCredentialsManage;
    }

    /** Editing an existing row would mean loading the token into a form. */
    public static function canEdit(mixed $record): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('provider'))
            ->columns([
                Tables\Columns\TextColumn::make('provider.code')->label('Provider')->searchable()->sortable(),
                Tables\Columns\IconColumn::make('is_active')->label('Active')->boolean()->sortable(),
                Tables\Columns\TextColumn::make('last_validated_at')
                    ->label('Last validated')->dateTime()->placeholder('—'),
                Tables\Columns\TextColumn::make('created_at')->dateTime()->sortable(),
                Tables\Columns\TextColumn::make('updated_at')->dateTime()->toggleable(),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('is_active'),
            ])
            // No view action: there is nothing safe to put on a detail page
            // that is not already in this table.
            ->actions([])
            ->bulkActions([])
            ->defaultSort('id', 'desc')
            ->headerActions([
                self::replaceAction(),
            ]);
    }

    /**
     * @return array<string, \Filament\Resources\Pages\PageRegistration>
     */
    public static function getPages(): array
    {
        return [
            'index' => Pages\ListProviderCredentials::route('/'),
        ];
    }

    /**
     * Store a new token for a provider, retiring whatever it had.
     *
     * The previous credential is deactivated rather than deleted, so the
     * history of when a token changed survives; the old secret itself stays
     * encrypted and unreadable either way. The audit entry records that a
     * replacement happened and by whom — never any part of either value.
     */
    private static function replaceAction(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('replaceCredential')
            ->label('Set provider token')
            ->icon('heroicon-o-key')
            ->color('primary')
            ->requiresConfirmation()
            ->modalHeading('Set a provider API token')
            ->modalDescription('The token is encrypted immediately and can never be displayed again. Any existing credential for this provider is deactivated.')
            ->form([
                Select::make('provider_id')
                    ->label('Provider')
                    ->options(fn (): array => Provider::query()->orderBy('code')->pluck('code', 'id')->all())
                    ->required()
                    ->searchable(),
                TextInput::make('api_token')
                    ->label('API token')
                    ->required()
                    ->password()
                    ->revealable(false)
                    ->autocomplete(false)
                    ->maxLength(500)
                    ->helperText('Write-only. Nothing in this panel will ever show it back to you.'),
            ])
            ->visible(fn (): bool => self::operatorMay(Permission::ProviderCredentialsManage))
            ->action(function (array $data): void {
                $operator = self::operator();
                $provider = Provider::query()->whereKey($data['provider_id'])->first();

                if (! $operator instanceof User || ! $provider instanceof Provider) {
                    return;
                }

                $token = trim((string) $data['api_token']);

                if ($token === '') {
                    Notification::make()->danger()->title('A token is required.')->send();

                    return;
                }

                // Retire the old one, then store the new. Both through the
                // model's encrypted cast; no plaintext is written anywhere.
                ProviderCredential::query()
                    ->where('provider_id', $provider->getKey())
                    ->update(['is_active' => false, 'updated_at' => now()]);

                ProviderCredential::query()->create([
                    'provider_id' => $provider->getKey(),
                    'credentials' => ['api_token' => $token],
                    'is_active' => true,
                ]);

                app(AuditRecorder::class)->record(
                    AuditEvent::ProviderCredentialReplaced,
                    actor: $operator,
                    subject: $provider,
                    metadata: [
                        'provider_id' => $provider->getKey(),
                        'provider_code' => $provider->code,
                        // That it changed, never what it changed to.
                        'replaced' => true,
                    ],
                );

                Notification::make()->success()->title('Provider token stored.')->send();
            });
    }
}
