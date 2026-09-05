<?php

declare(strict_types=1);

namespace App\Logging;

use App\Support\Secrets\SecretScrubber;
use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;

/**
 * Removes credentials from log records before they are written.
 *
 * This system handles provider API tokens, a Telegram bot token, database
 * passwords, TOTP secrets and server root passwords. None of them may ever
 * reach a log file, so redaction happens centrally here rather than being left
 * to each call site to remember.
 *
 * The rules live in SecretScrubber, shared with the audit trail.
 */
final class RedactSecretsProcessor implements ProcessorInterface
{
    public const REDACTED = SecretScrubber::REDACTED;

    public function __invoke(LogRecord $record): LogRecord
    {
        return $record->with(
            message: SecretScrubber::scrubText($record->message),
            context: SecretScrubber::scrub($record->context),
            extra: SecretScrubber::scrub($record->extra),
        );
    }
}
