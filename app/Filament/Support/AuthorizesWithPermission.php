<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Enums\Permission;
use App\Models\User;
use Illuminate\Support\Facades\Auth;

/**
 * Permission checks for a resource, asked of the request rather than the menu.
 *
 * Filament will happily hide a button an operator may not use, and hiding is
 * not a control: the Livewire endpoint behind it is still reachable. Every
 * resource that mixes this in answers the same question server-side, on every
 * call, from the authenticated operator's actual permissions.
 *
 * `checkPermissionTo` rather than `hasPermissionTo`: the latter throws when a
 * permission has never been seeded, and a gate that throws on a fresh database
 * is a gate somebody catches and turns into "allow".
 */
trait AuthorizesWithPermission
{
    /** The permission required to see this resource at all. */
    abstract public static function viewPermission(): Permission;

    /**
     * The permission required to change anything here.
     *
     * Null means nothing about this resource may be changed from the panel —
     * financial history, provider-owned facts, audit records. That is the
     * default for most of this panel, and it is deliberate.
     */
    public static function managePermission(): ?Permission
    {
        return null;
    }

    public static function canViewAny(): bool
    {
        return self::operatorMay(static::viewPermission());
    }

    public static function canView(mixed $record): bool
    {
        return self::operatorMay(static::viewPermission());
    }

    public static function canCreate(): bool
    {
        return self::operatorMay(static::managePermission());
    }

    public static function canEdit(mixed $record): bool
    {
        return self::operatorMay(static::managePermission());
    }

    /**
     * Deletion is refused for everything in this panel.
     *
     * Orders, servers, ledger rows, audit entries and provisioning history are
     * the account of what happened to a customer's money and machines.
     * Removing one is never an operational fix; it is the loss of the evidence
     * somebody will need.
     */
    public static function canDelete(mixed $record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function canForceDelete(mixed $record): bool
    {
        return false;
    }

    public static function canRestore(mixed $record): bool
    {
        return false;
    }

    /** Whether the signed-in operator holds one named permission. */
    public static function operatorMay(?Permission $permission): bool
    {
        if ($permission === null) {
            return false;
        }

        $operator = Auth::user();

        return $operator instanceof User
            && $operator->isActive()
            && $operator->checkPermissionTo($permission->value);
    }

    /** The signed-in operator, for an action that has to record who acted. */
    public static function operator(): ?User
    {
        $operator = Auth::user();

        return $operator instanceof User ? $operator : null;
    }
}
