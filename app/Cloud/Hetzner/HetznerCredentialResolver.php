<?php

declare(strict_types=1);

namespace App\Cloud\Hetzner;

use App\Cloud\Enums\ProviderErrorCategory;
use App\Cloud\Exceptions\ProviderException;
use App\Models\Provider;
use App\Models\ProviderCredential;

/**
 * Finds the API token for the Hetzner provider row, and nothing else.
 *
 * Separate from the client so exactly one object ever holds the token and one
 * file has to be read to answer "where does the credential come from". It comes
 * from `provider_credentials`, encrypted at rest and hidden from serialisation,
 * and there is deliberately no environment fallback: a token in the environment
 * would be a second place to look, a second place to leak from, and a way for a
 * misconfigured host to quietly spend money against the wrong account.
 *
 * Every failure here is a normalized configuration failure rather than a null.
 * A provider that cannot authenticate must not look like a provider that has no
 * servers.
 */
final readonly class HetznerCredentialResolver implements HetznerCredentials
{
    public function __construct(private HetznerSettings $settings) {}

    /**
     * The active API token for Hetzner.
     *
     * @throws ProviderException When the provider, the credential or the token
     *                           is missing.
     */
    public function token(): string
    {
        $credential = $this->credential();

        /** @var array<string, mixed> $credentials */
        $credentials = $credential->credentials ?? [];

        $token = $credentials['api_token'] ?? null;

        if (! is_string($token) || trim($token) === '') {
            // Never says what was found instead: the value is the secret.
            throw $this->configurationFailure('The Hetzner credential has no api_token.');
        }

        return trim($token);
    }

    /** The stored provider row, which also carries the non-secret settings. */
    public function provider(): Provider
    {
        $provider = Provider::query()->where('code', HetznerProvider::CODE)->first();

        if (! $provider instanceof Provider) {
            throw $this->configurationFailure('No Hetzner provider is configured.');
        }

        return $provider;
    }

    /** The provider's non-secret behaviour, read from `settings`. */
    public function settings(): HetznerSettings
    {
        return $this->settings->forProvider($this->provider());
    }

    private function credential(): ProviderCredential
    {
        $credential = ProviderCredential::query()
            ->where('provider_id', $this->provider()->getKey())
            ->where('is_active', true)
            ->latest('id')
            ->first();

        if (! $credential instanceof ProviderCredential) {
            throw $this->configurationFailure('Hetzner has no active API credential.');
        }

        return $credential;
    }

    /**
     * A missing credential is an authentication failure, not a transient one.
     *
     * The category matters more than it looks: `Authentication` is never
     * retried and never treated as an unknown outcome, which is right — no
     * request left this process, so nothing can have happened remotely.
     */
    private function configurationFailure(string $message): ProviderException
    {
        return ProviderException::make(
            ProviderErrorCategory::Authentication,
            HetznerProvider::CODE,
            $message,
        );
    }
}
