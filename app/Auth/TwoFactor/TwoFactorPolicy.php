<?php

declare(strict_types=1);

namespace App\Auth\TwoFactor;

use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Foundation\Application;

/**
 * Decides whether a second factor is mandatory.
 *
 * Fails closed: in production the answer is always yes, and no configuration
 * value, environment variable or forgotten override can change that. The
 * setting exists only so that automated tests can exercise the path where
 * enrolment has not happened yet.
 */
final readonly class TwoFactorPolicy
{
    public function __construct(
        private Application $app,
        private Config $config,
    ) {}

    public function isRequired(): bool
    {
        // Checked first and returned unconditionally. Production is not
        // configurable here on purpose.
        if ($this->app->environment('production')) {
            return true;
        }

        return (bool) $this->config->get('cloudbot.admin.require_two_factor', true);
    }
}
