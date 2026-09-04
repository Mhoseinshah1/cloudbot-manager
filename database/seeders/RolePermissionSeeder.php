<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Authorization\RoleProvisioner;
use Illuminate\Database\Seeder;

/**
 * Puts the privileged roles in place.
 *
 * Safe to run repeatedly, and safe to run on an existing deployment.
 */
class RolePermissionSeeder extends Seeder
{
    public function run(RoleProvisioner $provisioner): void
    {
        $result = $provisioner->sync();

        $this->command?->info(sprintf(
            'Synced %d roles and %d permissions.',
            $result['roles'],
            $result['permissions'],
        ));
    }
}
