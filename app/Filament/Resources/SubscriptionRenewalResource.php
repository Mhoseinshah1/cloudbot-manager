<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Enums\Permission;
use App\Filament\Resources\SubscriptionRenewalResource\Pages;
use App\Filament\Support\AuthorizesWithPermission;
use App\Models\SubscriptionRenewal;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Each service period a customer actually paid to extend.
 *
 * Append-only financial history: the unique key on each row is what makes a
 * period chargeable once, so nothing here may be edited or removed.
 */
class SubscriptionRenewalResource extends Resource
{
    use AuthorizesWithPermission;

    protected static ?string $model = SubscriptionRenewal::class;

    protected static ?string $navigationIcon = 'heroicon-o-arrow-path-rounded-square';

    protected static ?string $navigationGroup = 'Billing';

    protected static ?int $navigationSort = 40;

    protected static ?string $navigationLabel = 'Subscription Renewals';

    public static function viewPermission(): Permission
    {
        return Permission::SubscriptionsView;
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
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['subscription', 'invoice']))
            ->columns([
                Tables\Columns\TextColumn::make('id')->label('ID')->sortable(),
                Tables\Columns\TextColumn::make('subscription_id')->label('Subscription')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('user_id')->label('Customer')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('amount_toman')->label('Amount')->numeric()->sortable(),
                Tables\Columns\TextColumn::make('old_period_end')->label('From')->dateTime()->sortable(),
                Tables\Columns\TextColumn::make('new_period_end')->label('To')->dateTime()->sortable(),
                Tables\Columns\TextColumn::make('invoice.number')->label('Invoice')->searchable()->sortable()->placeholder('—'),
                Tables\Columns\TextColumn::make('created_at')->dateTime()->sortable()->toggleable(),
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
     * @return array<string, \Filament\Resources\Pages\PageRegistration>
     */
    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSubscriptionRenewals::route('/'),
        ];
    }
}
