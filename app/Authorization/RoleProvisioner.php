<?php

declare(strict_types=1);

namespace App\Authorization;

use App\Enums\AdminRole;
use App\Enums\Permission;
use Spatie\Permission\Models\Permission as PermissionModel;
use Spatie\Permission\Models\Role as RoleModel;
use Spatie\Permission\PermissionRegistrar;

/**
 * Creates the privileged roles and their permissions.
 *
 * Idempotent by design: it runs during installation, during updates and in
 * tests, and running it twice must be indistinguishable from running it once.
 *
 * It also removes permissions a role should no longer hold. Without that, a
 * permission withdrawn in code would linger in the database of every existing
 * deployment, which is exactly the kind of quiet privilege drift that makes an
 * authorization model untrustworthy.
 */
final class RoleProvisioner
{
    public function __construct(private readonly PermissionRegistrar $registrar) {}

    /**
     * @return array{roles: int, permissions: int}
     */
    public function sync(string $guard = 'web'): array
    {
        // Read from the database, not from a cache that may describe an older
        // state. Spatie's permission map lives in the cache store, which
        // outlives a rolled-back transaction and survives a manual change made
        // directly in the database.
        $this->registrar->forgetCachedPermissions();

        foreach (Permission::cases() as $permission) {
            PermissionModel::findOrCreate($permission->value, $guard);
        }

        // Spatie resolves permission names through a cache. Without clearing it
        // here, the roles below are assigned against the map as it looked
        // before these permissions existed, and syncing raises.
        $this->registrar->forgetCachedPermissions();

        foreach (AdminRole::cases() as $role) {
            $model = RoleModel::findOrCreate($role->value, $guard);

            // syncPermissions, not givePermissionTo: the definition in code is
            // the whole truth, so anything not listed there is revoked.
            $model->syncPermissions($role->permissionValues());
        }

        // Spatie caches the permission map; without this the caller would keep
        // seeing the state from before the sync.
        $this->registrar->forgetCachedPermissions();

        return [
            'roles' => count(AdminRole::cases()),
            'permissions' => count(Permission::cases()),
        ];
    }
}
