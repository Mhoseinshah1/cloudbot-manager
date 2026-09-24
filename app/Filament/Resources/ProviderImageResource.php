<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Enums\Permission;
use App\Filament\Resources\ProviderImageResource\Pages;
use App\Filament\Support\AuthorizesWithPermission;
use App\Models\ProviderImage;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * The operating systems a provider will install.
 *
 * `deprecated` is the provider's word and is synced; `enabled` is ours. An
 * image the provider has withdrawn stops being offered without anybody having
 * to remember to switch it off.
 */
class ProviderImageResource extends Resource
{
    use AuthorizesWithPermission;

    protected static ?string $model = ProviderImage::class;

    protected static ?string $navigationIcon = 'heroicon-o-photo';

    protected static ?string $navigationGroup = 'Catalog';

    protected static ?int $navigationSort = 50;

    protected static ?string $navigationLabel = 'Provider Images';

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
                Tables\Columns\TextColumn::make('provider_image_id')->label('Provider ID')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('name')->label('Name')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('os_family')->label('OS')->sortable()->toggleable(),
                Tables\Columns\TextColumn::make('version')->label('Version')->sortable()->toggleable(),
                Tables\Columns\TextColumn::make('architecture')->label('Arch')->sortable()->toggleable(),
                Tables\Columns\IconColumn::make('deprecated')->label('Deprecated')->boolean()->sortable(),
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
            'index' => Pages\ListProviderImages::route('/'),
            'edit' => Pages\EditProviderImage::route('/{record}/edit'),
        ];
    }
}
