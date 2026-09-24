<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Enums\Permission;
use App\Filament\Resources\PaymentResource\Pages;
use App\Filament\Support\AuthorizesWithPermission;
use App\Models\Payment;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Settlement facts, as recorded by the payment layer.
 *
 * Read-only here. A payment's status is reached by PaymentService verifying
 * something real; setting it from a dropdown would credit a wallet for money
 * nobody received.
 */
class PaymentResource extends Resource
{
    use AuthorizesWithPermission;

    protected static ?string $model = Payment::class;

    protected static ?string $navigationIcon = 'heroicon-o-credit-card';

    protected static ?string $navigationGroup = 'Billing';

    protected static ?int $navigationSort = 10;

    protected static ?string $navigationLabel = 'Payments';

    public static function viewPermission(): Permission
    {
        return Permission::PaymentsView;
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
                Tables\Columns\TextColumn::make('gateway')->badge()->sortable(),
                Tables\Columns\TextColumn::make('amount_toman')->label('Amount')->numeric()->sortable(),
                Tables\Columns\TextColumn::make('status')->badge()->sortable(),
                Tables\Columns\TextColumn::make('provider_payment_id')->label('Gateway ref')->searchable()->sortable()->toggleable()->placeholder('—'),
                Tables\Columns\TextColumn::make('paid_at')->dateTime()->sortable()->placeholder('—'),
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
            'index' => Pages\ListPayments::route('/'),
        ];
    }
}
