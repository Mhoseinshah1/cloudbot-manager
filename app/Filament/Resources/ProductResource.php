<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Enums\BillingCycle;
use App\Enums\BillingMode;
use App\Enums\Permission;
use App\Filament\Resources\ProductResource\Pages;
use App\Filament\Support\AuthorizesWithPermission;
use App\Models\Product;
use App\Models\Provider;
use App\Models\ProviderPlan;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * What this installation actually sells.
 *
 * Local sales configuration, and the one catalog resource an operator genuinely
 * owns: a product is this system's decision to offer a provider plan to
 * customers. Billing mode and cycle are fixed to monthly, because monthly is
 * what Release 1.0 implements — offering a mode with no code behind it would
 * sell something that cannot be billed.
 */
class ProductResource extends Resource
{
    use AuthorizesWithPermission;

    protected static ?string $model = Product::class;

    protected static ?string $navigationIcon = 'heroicon-o-shopping-bag';

    protected static ?string $navigationGroup = 'Catalog';

    protected static ?int $navigationSort = 10;

    public static function viewPermission(): Permission
    {
        return Permission::ProvidersView;
    }

    public static function managePermission(): ?Permission
    {
        return Permission::ProviderCatalogManage;
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            TextInput::make('name')->required()->maxLength(120),
            Textarea::make('description')->maxLength(500)->columnSpanFull(),
            Select::make('provider_id')
                ->label('Provider')
                ->options(fn (): array => Provider::query()->orderBy('code')->pluck('code', 'id')->all())
                ->required()
                ->searchable(),
            Select::make('provider_plan_id')
                ->label('Provider plan')
                ->options(fn (): array => ProviderPlan::query()
                    ->orderBy('name')
                    ->pluck('name', 'id')
                    ->all())
                ->required()
                ->searchable()
                ->helperText('The plan must belong to the provider chosen above; pricing refuses a mismatch.'),
            // Fixed, not chosen. Hourly and hourly-capped are Release 1.1 and
            // have no billing code behind them.
            Select::make('billing_mode')
                ->options([BillingMode::Monthly->value => 'Monthly'])
                ->default(BillingMode::Monthly->value)
                ->required()
                ->disabled()
                ->dehydrated(),
            Select::make('billing_cycle')
                ->options([BillingCycle::Monthly->value => 'Monthly'])
                ->default(BillingCycle::Monthly->value)
                ->required()
                ->disabled()
                ->dehydrated(),
            TextInput::make('sort_order')->numeric()->default(0),
            Toggle::make('active')->label('Offered to customers')->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['provider', 'providerPlan']))
            ->columns([
                Tables\Columns\TextColumn::make('name')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('provider.code')->label('Provider')->sortable(),
                Tables\Columns\TextColumn::make('providerPlan.name')->label('Plan')->searchable(),
                Tables\Columns\TextColumn::make('billing_mode')->badge(),
                Tables\Columns\IconColumn::make('active')->boolean()->sortable(),
                Tables\Columns\TextColumn::make('sort_order')->numeric()->toggleable(),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('active'),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([])
            ->defaultSort('sort_order');
    }

    /**
     * @return array<string, \Filament\Resources\Pages\PageRegistration>
     */
    public static function getPages(): array
    {
        return [
            'index' => Pages\ListProducts::route('/'),
            'create' => Pages\CreateProduct::route('/create'),
            'edit' => Pages\EditProduct::route('/{record}/edit'),
        ];
    }
}
