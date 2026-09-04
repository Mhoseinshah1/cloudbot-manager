<?php

declare(strict_types=1);

namespace App\Cloud;

use App\Cloud\Contracts\CloudProviderInterface;
use App\Cloud\Enums\ProviderCapability;
use App\Cloud\Enums\ProviderErrorCategory;
use App\Cloud\Exceptions\ProviderException;
use App\Models\Provider;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Container\Container;

/**
 * Resolves a provider code to its implementation.
 *
 * The single place where a string becomes an object that will be asked to spend
 * money. Codes are looked up in the static registry and nowhere else: if the
 * registry does not name a class, none is instantiated, whatever the database
 * says.
 */
final readonly class ProviderManager
{
    public function __construct(
        private Container $container,
        private Config $config,
    ) {}

    /**
     * Resolve an implementation by its registry code.
     *
     * @throws ProviderException When the code is not registered, or the class
     *                           behind it is not a provider.
     */
    public function driver(string $code): CloudProviderInterface
    {
        $registry = $this->registry();

        // Not array_key_exists on user input alone: the value must also be a
        // class we recognise as a provider, checked below.
        if (! array_key_exists($code, $registry)) {
            throw ProviderException::make(
                ProviderErrorCategory::InvalidRequest,
                $code,
                'No provider implementation is registered for this code.',
            );
        }

        $class = $registry[$code];

        if (! is_string($class) || ! is_a($class, CloudProviderInterface::class, true)) {
            // A registry entry that is not a provider is a configuration
            // mistake, and instantiating it anyway is how that mistake becomes
            // an incident.
            throw ProviderException::make(
                ProviderErrorCategory::InvalidRequest,
                $code,
                'The registered provider implementation is not a cloud provider.',
            );
        }

        $provider = $this->container->make($class);

        if (! $provider instanceof CloudProviderInterface) {
            throw ProviderException::make(
                ProviderErrorCategory::InvalidRequest,
                $code,
                'The registered provider implementation is not a cloud provider.',
            );
        }

        return $provider;
    }

    /**
     * Resolve the implementation for a stored provider row.
     *
     * @throws ProviderException When the provider is switched off.
     */
    public function for(Provider $provider): CloudProviderInterface
    {
        if (! $provider->enabled) {
            // An operator disabled this provider, usually during an incident.
            // Honouring that is the whole point of the switch.
            throw ProviderException::make(
                ProviderErrorCategory::Unavailable,
                $provider->code,
                'This provider is disabled.',
            );
        }

        return $this->driver($provider->code);
    }

    /**
     * The codes this build can serve.
     *
     * @return list<string>
     */
    public function registeredCodes(): array
    {
        return array_keys($this->registry());
    }

    public function isRegistered(string $code): bool
    {
        return array_key_exists($code, $this->registry());
    }

    /**
     * What a provider can actually do, asked of the implementation itself.
     *
     * @return list<ProviderCapability>
     */
    public function capabilitiesFor(CloudProviderInterface $provider): array
    {
        return ProviderCapability::offeredBy($provider);
    }

    /**
     * @return array<string, mixed>
     */
    private function registry(): array
    {
        $registry = $this->config->get('providers.implementations', []);

        return is_array($registry) ? $registry : [];
    }
}
