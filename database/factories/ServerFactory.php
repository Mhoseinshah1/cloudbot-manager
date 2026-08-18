<?php

namespace Database\Factories;

use App\Models\Provider;
use App\Models\ProviderLocation;
use App\Models\Server;
use App\Models\User;
use App\Providers\Cloud\FakeProvider;
use Illuminate\Database\Eloquent\Factories\Factory;

class ServerFactory extends Factory
{
    protected $model = Server::class;

    public function definition(): array
    {
        $provider = Provider::firstOrCreate(
            ['code' => 'fake'],
            [
                'name' => 'Fake Provider',
                'class' => FakeProvider::class,
                'enabled' => true,
                'capabilities' => [
                    'supportsPowerOn' => true,
                    'supportsPowerOff' => true,
                    'supportsReboot' => true,
                    'supportsRebuild' => true,
                    'supportsResetPassword' => true,
                    'supportsSnapshots' => false,
                    'supportsSuspend' => false,
                    'supportsUsage' => true,
                ],
            ]
        );

        $location = ProviderLocation::firstOrCreate(
            ['provider_id' => $provider->id, 'provider_location_id' => 'fsn1'],
            [
                'name' => 'Falkenstein',
                'country_code' => 'DE',
                'city' => 'Falkenstein',
                'enabled' => true,
            ]
        );

        return [
            'user_id' => User::factory(),
            'provider_id' => $provider->id,
            'provider_location_id' => $location->id,
            'provider_server_id' => 'fake-'.fake()->unique()->randomNumber(5),
            'name' => 'test-server-'.fake()->unique()->randomNumber(5),
            'ip_address' => fake()->ipv4(),
            'status' => Server::STATUS_RUNNING,
            'power_state' => 'running',
            'plan_snapshot' => ['vcpu' => 2, 'ram_mb' => 4096, 'disk_gb' => 80],
            'image_snapshot' => ['name' => 'Ubuntu 24.04', 'os_distro' => 'ubuntu', 'version' => '24.04'],
        ];
    }

    public function running(): static
    {
        return $this->state(fn () => ['status' => Server::STATUS_RUNNING, 'power_state' => 'running']);
    }

    public function off(): static
    {
        return $this->state(fn () => ['status' => Server::STATUS_OFF, 'power_state' => 'off']);
    }
}
