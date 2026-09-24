<?php

declare(strict_types=1);

namespace App\Cloud\Hetzner;

use App\Cloud\Exceptions\ProviderException;

/**
 * Where the HTTP client gets its token and its endpoint.
 *
 * An interface rather than the concrete resolver so the transport depends on
 * the question ("what token, what base URL") instead of on the answer ("a row
 * in provider_credentials"). That keeps the credential in exactly one class in
 * production, and lets a test drive the whole adapter against `Http::fake()`
 * without seeding a provider row or inventing a token.
 */
interface HetznerCredentials
{
    /**
     * The active API token.
     *
     * @throws ProviderException When no usable credential is configured.
     */
    public function token(): string;

    /** Where to call, and how long to wait. */
    public function settings(): HetznerSettings;
}
