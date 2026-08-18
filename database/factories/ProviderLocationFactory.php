<?php

namespace Database\Factories;

use App\Models\Provider;
use App\Models\ProviderLocation;
use Illuminate\Database\Eloquent\Factories\Factory;

class ProviderLocationFactory extends Factory
{
    protected $model = ProviderLocation::class;

    public function definition(): array
    {
        return [
            'provider_id' => Provider::factory(),
            'provider_location_id' => 'fsn1',
            'name' => 'Falkenstein',
            'country_code' => 'DE',
            'city' => 'Falkenstein',
            'enabled' => true,
        ];
    }
}
