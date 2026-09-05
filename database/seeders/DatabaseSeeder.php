<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seeds only what every deployment needs: the privileged roles.
     *
     * No business values are seeded. Settings arrive with the features that
     * read them, and seeding a threshold before anything consumes it would be
     * a value nobody owns.
     */
    public function run(): void
    {
        $this->call(RolePermissionSeeder::class);
    }
}
