<?php

declare(strict_types=1);

namespace App\Logging;

use Illuminate\Log\Logger;
use Monolog\Logger as Monolog;
use RuntimeException;

/**
 * Attaches the redaction processor to a log channel.
 *
 * Referenced from config/logging.php as a channel `tap`, so every channel
 * redacts, including channels added later.
 */
final class RedactSecrets
{
    public function __invoke(Logger $logger): void
    {
        $monolog = $logger->getLogger();

        if (! $monolog instanceof Monolog) {
            // Redaction is a security control. Failing loudly is correct here:
            // silently skipping it would let credentials reach the logs.
            throw new RuntimeException(
                'Cannot attach secret redaction: the channel is not backed by Monolog.'
            );
        }

        $monolog->pushProcessor(new RedactSecretsProcessor);
    }
}
