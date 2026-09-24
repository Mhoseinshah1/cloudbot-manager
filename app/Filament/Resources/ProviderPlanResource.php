<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Enums\Permission;
use App\Filament\Resources\ProviderPlanResource\Pages;
use App\Filament\Support\AuthorizesWithPermission;
use App\Models\ProviderPlan;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * The machine sizes each provider offers.
 *
 * Hardware facts and provider prices are synced, never typed. Only `enabled`
 * is the operator's.
 */
class ProviderPlanResource extends Resource
{
    use AuthorizesWithPermission;

    protected static ?string $model = ProviderPlan::class;

    protected static ?string $navigationIcon = 'heroicon-o-cpu-chip';

    protected static ?string $navigationGroup = 'Catalog';

    protected static ?int $navigationSort = 40;

    protected static ?string $navigationLabel = 'Provider Plans';

    public static function viewPermission(): Permission
    {
        return Permission::ProvidersView;
    }

    public static function managePermission(): ?Permission
    {
        return Permission::ProviderCatalogManage;
    }

    /** Rows arrive from the provider sync, never from an operator. */
    public static function canCreate(): bool
    {
        return false;
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            // The one local decision. Everything else on this row is the
            // provider's and is rewritten by the next sync.
            Toggle::make('enabled')
                ->label('Enabled')
                ->helperText('Our own switch. Provider-owned fields are synced and cannot be edited here.'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('provider'))
            ->columns([
                Tables\Columns\TextColumn::make('provider.code')->label('Provider')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('provider_plan_id')->label('Provider ID')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('name')->label('Name')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('vcpu')->label('vCPU')->numeric()->sortable(),
                Tables\Columns\TextColumn::make('ram_mb')->label('RAM MB')->numeric()->sortable(),
                Tables\Columns\TextColumn::make('disk_gb')->label('Disk GB')->numeric()->sortable(),
                Tables\Columns\TextColumn::make('provider_price_monthly')->label('Cost/mo')->sortable()->toggleable(),
                Tables\Columns\TextColumn::make('provider_currency')->label('Currency')->sortable()->toggleable(),
                Tables\Columns\IconColumn::make('enabled')->label('Enabled')->boolean()->sortable(),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('enabled'),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([])
            ->defaultSort('id');
    }

    /**
     * @return array<string, class-string>
     */
    public static function getPages(): array
    {
        return [
            'index' => Pages\ListProviderPlans::route('/'),
            'edit' => Pages\EditProviderPlan::route('/{record}/edit'),
        ];
    }
}
