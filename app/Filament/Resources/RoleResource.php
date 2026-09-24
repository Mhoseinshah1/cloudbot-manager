<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Enums\AdminRole;
use App\Enums\Permission;
use App\Filament\Resources\RoleResource\Pages;
use App\Filament\Support\AuthorizesWithPermission;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Spatie\Permission\Models\Role;

/**
 * The privileged roles, shown and never edited here.
 *
 * `owner`, `finance` and `support` are defined in `AdminRole` and provisioned
 * by `RoleProvisioner`, which is deliberately the only thing that writes them.
 * Editing a role's permissions from a screen would put the database and the
 * code out of step: the next `roles:sync` would silently undo the change, and
 * in the meantime the boundary between finance and support — which exists to
 * keep the person who can move money separate from the person who can delete
 * servers — would be whatever somebody last clicked.
 *
 * Changing what a role may do is a reviewed edit to `AdminRole`.
 */
class RoleResource extends Resource
{
    use AuthorizesWithPermission;

    protected static ?string $model = Role::class;

    protected static ?string $navigationIcon = 'heroicon-o-identification';

    protected static ?string $navigationGroup = 'System';

    protected static ?int $navigationSort = 20;

    public static function viewPermission(): Permission
    {
        return Permission::RolesManage;
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
                ->withCount(['permissions', 'users']))
            ->columns([
                Tables\Columns\TextColumn::make('name')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('guard_name')->label('Guard')->toggleable(),
                Tables\Columns\IconColumn::make('code_defined')
                    ->label('Defined in code')
                    ->boolean()
                    ->state(fn (Role $record): bool => AdminRole::tryFrom($record->name) !== null),
                Tables\Columns\TextColumn::make('permissions_count')->label('Permissions')->numeric(),
                Tables\Columns\TextColumn::make('users_count')->label('Holders')->numeric(),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
            ])
            ->bulkActions([])
            ->defaultSort('name');
    }

    /**
     * @return array<string, class-string>
     */
    public static function getPages(): array
    {
        return [
            'index' => Pages\ListRoles::route('/'),
            'view' => Pages\ViewRole::route('/{record}'),
        ];
    }
}
