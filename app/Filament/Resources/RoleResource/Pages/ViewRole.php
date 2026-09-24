<?php

declare(strict_types=1);

namespace App\Filament\Resources\RoleResource\Pages;

use App\Filament\Resources\RoleResource;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Resources\Pages\ViewRecord;
use Spatie\Permission\Models\Role;

/**
 * What one role may do, as currently provisioned.
 */
class ViewRole extends ViewRecord
{
    protected static string $resource = RoleResource::class;

    public function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            TextEntry::make('name'),
            TextEntry::make('guard_name')->label('Guard'),
            TextEntry::make('permissions')
                ->label('Permissions')
                ->state(fn (Role $record): string => $record->permissions
                    ->pluck('name')
                    ->sort()
                    ->implode("\n"))
                ->columnSpanFull(),
        ]);
    }
}
