<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Provider;
use App\Models\ProviderCredential;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProviderCredential>
 */
class ProviderCredentialFactory extends Factory
{
    protected $model = ProviderCredential::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'provider_id' => Provider::factory(),
            // Obviously synthetic. Nothing here is or resembles a real token.
            'credentials' => ['api_token' => 'test-token-not-a-real-credential'],
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (): array => ['is_active' => false]);
    }
}
