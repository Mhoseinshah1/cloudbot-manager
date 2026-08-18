<?php

namespace Database\Factories;

use App\Models\Provider;
use App\Providers\Cloud\FakeProvider;
use Illuminate\Database\Eloquent\Factories\Factory;

class ProviderFactory extends Factory
{
    protected $model = Provider::class;

    public function definition(): array
    {
        return [
            'code' => 'fake',
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
        ];
    }
}
