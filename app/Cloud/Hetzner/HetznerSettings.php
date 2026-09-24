<?php

declare(strict_types=1);

namespace App\Cloud\Hetzner;

use App\Cloud\Enums\ProviderErrorCategory;
use App\Cloud\Exceptions\ProviderException;
use App\Models\Provider;
use Illuminate\Contracts\Config\Repository as Config;

/**
 * Non-secret Hetzner behaviour: where to call, and how long to wait.
 *
 * `Provider.settings` is a plain JSON column an operator can edit, so it holds
 * only things that are safe to read in an admin screen. The API token is not
 * one of them and lives encrypted in `provider_credentials`.
 *
 * The base URL override exists so a local or CI environment can point at a
 * stand-in service. It is validated rather than trusted: an unvalidated
 * operator-editable URL is a way to send a bearer token somewhere else, so the
 * scheme must be https (except for an explicit localhost fixture) and the URL
 * may carry no query or credentials of its own.
 */
final readonly class HetznerSettings
{
    public const DEFAULT_BASE_URL = 'https://api.hetzner.cloud/v1';

    public function __construct(
        private Config $config,
        public string $baseUrl = self::DEFAULT_BASE_URL,
        public ?int $timeoutOverride = null,
    ) {}

    /** The same settings, resolved against one stored provider row. */
    public function forProvider(Provider $provider): self
    {
        /** @var array<string, mixed> $settings */
        $settings = $provider->settings ?? [];

        $base = $settings['api_base_url'] ?? null;
        $timeout = $settings['timeout_seconds'] ?? null;

        return new self(
            $this->config,
            is_string($base) && $base !== '' ? self::validateBaseUrl($base) : self::DEFAULT_BASE_URL,
            is_int($timeout) && $timeout > 0 ? $timeout : null,
        );
    }

    /**
     * How long one Hetzner call may take.
     *
     * Grounded in the existing provider timeout so the whole system agrees on
     * what "too long" means — the server-action lock TTL and the in-flight
     * reservation grace are both derived from the same number.
     */
    public function timeoutSeconds(): int
    {
        return max(1, $this->timeoutOverride
            ?? (int) $this->config->get('cloudbot.provisioning.provider_timeout_seconds', 120));
    }

    /** A connect timeout well inside the request timeout: a refused TCP connect is fast. */
    public function connectTimeoutSeconds(): int
    {
        return max(1, (int) min(10, $this->timeoutSeconds()));
    }

    /**
     * Refuse a base URL that could send the bearer token somewhere unintended.
     */
    public static function validateBaseUrl(string $url): string
    {
        $trimmed = rtrim(trim($url), '/');
        $parts = parse_url($trimmed);

        $scheme = is_array($parts) ? ($parts['scheme'] ?? null) : null;
        $host = is_array($parts) ? ($parts['host'] ?? null) : null;

        $localhost = is_string($host) && in_array($host, ['localhost', '127.0.0.1', '::1'], true);

        if (! is_string($host) || $host === '' || ($scheme !== 'https' && ! ($scheme === 'http' && $localhost))) {
            throw ProviderException::make(
                ProviderErrorCategory::InvalidRequest,
                HetznerProvider::CODE,
                'The Hetzner API base URL must be an https URL.',
            );
        }

        if (isset($parts['query']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])) {
            // A credential or query in the base URL would be repeated on every
            // request and copied into anything that logs a URL.
            throw ProviderException::make(
                ProviderErrorCategory::InvalidRequest,
                HetznerProvider::CODE,
                'The Hetzner API base URL may not carry a query string or credentials.',
            );
        }

        return $trimmed;
    }
}
