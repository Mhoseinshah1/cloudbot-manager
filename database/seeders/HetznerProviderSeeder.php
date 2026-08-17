<?php

namespace Database\Seeders;

use App\Models\Provider;
use App\Providers\Cloud\HetznerProvider;
use Illuminate\Database\Seeder;

class HetznerProviderSeeder extends Seeder
{
    public function run(): void
    {
        Provider::query()->updateOrCreate(
            ['code' => 'hetzner'],
            [
                'name' => 'Hetzner Cloud',
                'class' => HetznerProvider::class,
                'enabled' => false, // enabled once an API token credential is added
                'capabilities' => (new HetznerProvider)->capabilities(),
            ]
        );
    }
}
