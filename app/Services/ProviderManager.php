<?php

namespace App\Services;

use App\Contracts\CloudProviderInterface;
use App\Exceptions\ProviderException;
use App\Models\Provider;
use InvalidArgumentException;

class ProviderManager
{
    /** @var array<string, CloudProviderInterface> */
    private array $instances = [];

    public function resolve(Provider $provider): CloudProviderInterface
    {
        if (! $provider->enabled) {
            throw ProviderException::unavailable('Provider', $provider->code);
        }

        return $this->instances[$provider->code] ??= $this->build($provider);
    }

    public function resolveByCode(string $code): CloudProviderInterface
    {
        $provider = Provider::query()->where('code', $code)->firstOrFail();

        return $this->resolve($provider);
    }

    /**
     * Builds an adapter even for disabled providers — used by catalog sync
     * and reconciliation, which must not be blocked by an enabled flag.
     */
    public function resolveForSync(Provider $provider): CloudProviderInterface
    {
        return $this->build($provider);
    }

    public function build(Provider $provider): CloudProviderInterface
    {
        $class = $provider->class;

        if (! class_exists($class)) {
            throw new InvalidArgumentException("Provider class [{$class}] does not exist.");
        }

        $adapter = new $class(
            credentials: $this->credentialsFor($provider),
            options: $provider->settings ?? [],
        );

        if (! $adapter instanceof CloudProviderInterface) {
            throw new InvalidArgumentException("Provider class [{$class}] must implement CloudProviderInterface.");
        }

        return $adapter;
    }

    /**
     * Returns the first active credential set, decrypted by the model cast.
     *
     * @return array<string, mixed>
     */
    private function credentialsFor(Provider $provider): array
    {
        $credential = $provider->credentials()->where('is_active', true)->first();

        return $credential === null ? [] : $credential->credentials;
    }
}
