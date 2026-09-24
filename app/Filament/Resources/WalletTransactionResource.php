<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Enums\Permission;
use App\Filament\Resources\WalletTransactionResource\Pages;
use App\Filament\Support\AuthorizesWithPermission;
use App\Models\WalletTransaction;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * The immutable wallet ledger.
 *
 * Read-only by design: the balance is the sum of these rows, so a row that
 * could be edited would be a balance nobody can reconstruct. Adjustments are
 * made from the customer screen, through WalletService, and appear here.
 */
class WalletTransactionResource extends Resource
{
    use AuthorizesWithPermission;

    protected static ?string $model = WalletTransaction::class;

    protected static ?string $navigationIcon = 'heroicon-o-banknotes';

    protected static ?string $navigationGroup = 'Billing';

    protected static ?int $navigationSort = 30;

    protected static ?string $navigationLabel = 'Wallet Transactions';

    public static function viewPermission(): Permission
    {
        return Permission::WalletView;
    }

    // Nothing here may be changed from the panel.
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
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['user']))
            ->columns([
                Tables\Columns\TextColumn::make('id')->label('ID')->sortable(),
                Tables\Columns\TextColumn::make('user.name')->label('Customer')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('type')->badge()->sortable(),
                Tables\Columns\TextColumn::make('amount_toman')->label('Amount')->numeric()->sortable(),
                Tables\Columns\TextColumn::make('balance_before_toman')->label('Before')->numeric()->sortable()->toggleable(),
                Tables\Columns\TextColumn::make('balance_after_toman')->label('After')->numeric()->sortable()->toggleable(),
                Tables\Columns\TextColumn::make('idempotency_key')->label('Key')->searchable()->sortable()->toggleable()->limit(40),
                Tables\Columns\TextColumn::make('description')->sortable()->toggleable()->limit(40)->placeholder('—'),
                Tables\Columns\TextColumn::make('created_at')->dateTime()->sortable(),
            ])
            ->filters([
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
            ])
            // History is never edited or removed in bulk.
            ->bulkActions([])
            ->defaultSort('id', 'desc');
    }

    /**
     * @return array<string, class-string>
     */
    public static function getPages(): array
    {
        return [
            'index' => Pages\ListWalletTransactions::route('/'),
        ];
    }
}
