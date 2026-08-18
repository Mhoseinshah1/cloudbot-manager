<?php

namespace App\Filament\Resources\ServerResource\RelationManagers;

use App\Models\ServerBillingPeriod;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Read-only hourly billing ledger. Financial history is immutable for
 * auditing purposes — no create/edit/delete actions are exposed.
 */
class ServerBillingPeriodRelationManager extends RelationManager
{
    protected static string $relationship = 'billingPeriods';

    protected static ?string $title = 'Hourly billing ledger';

    protected static ?string $icon = 'heroicon-o-banknotes';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('id')
            ->columns([
                Tables\Columns\TextColumn::make('period_start')
                    ->dateTime()
                    ->sortable(),
                Tables\Columns\TextColumn::make('period_end')
                    ->dateTime()
                    ->sortable(),
                Tables\Columns\TextColumn::make('rate_toman')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('amount_toman')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('currency')
                    ->searchable(),
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => $state === ServerBillingPeriod::STATUS_PAID ? 'success' : 'warning')
                    ->searchable(),
                Tables\Columns\IconColumn::make('capped')
                    ->boolean(),
                Tables\Columns\TextColumn::make('reference_type')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('reference_id')
                    ->numeric()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('description')
                    ->limit(40)
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options([
                        ServerBillingPeriod::STATUS_PAID => 'Paid',
                        ServerBillingPeriod::STATUS_UNPAID => 'Unpaid',
                    ]),
            ])
            ->actions([])
            ->bulkActions([]);
    }
}
