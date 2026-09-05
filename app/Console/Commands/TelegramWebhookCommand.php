<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Telegram\Exceptions\TelegramApiException;
use App\Telegram\Exceptions\TelegramNotConfigured;
use App\Telegram\TelegramApiClient;
use Illuminate\Console\Command;

/**
 * Point the bot at this installation, or ask where it currently points.
 *
 * Operational plumbing, deliberately narrow. It reads the configured URL and
 * secret and calls Telegram; it does not discover a hostname, provision a
 * certificate or edit any configuration. Deployment owns all of that.
 *
 * Neither the bot token nor the webhook secret is ever printed, including in
 * the summary of what was just set. An operator running this in a terminal that
 * is being recorded, or piping it into a log, must not thereby publish the
 * credential.
 */
final class TelegramWebhookCommand extends Command
{
    protected $signature = 'telegram:webhook
        {action=info : One of info, set or delete}
        {--url= : Override the configured webhook URL when setting}';

    protected $description = 'Inspect, set or remove the Telegram webhook for this installation';

    public function handle(TelegramApiClient $telegram): int
    {
        $action = (string) $this->argument('action');

        try {
            return match ($action) {
                'info' => $this->info_($telegram),
                'set' => $this->set($telegram),
                'delete' => $this->delete($telegram),
                default => $this->unknown($action),
            };
        } catch (TelegramNotConfigured $missing) {
            // Names the setting, never its value.
            $this->error($missing->getMessage());

            return self::FAILURE;
        } catch (TelegramApiException $failure) {
            // Already scrubbed by the exception itself.
            $this->error($failure->getMessage());

            return self::FAILURE;
        }
    }

    private function info_(TelegramApiClient $telegram): int
    {
        $info = $telegram->getWebhookInfo();

        // Named fields only. The full response can carry a last-error string
        // that quotes our own request back at us.
        $this->line('URL: '.$this->describe($info['url'] ?? null));
        $this->line('Pending updates: '.$this->describe($info['pending_update_count'] ?? null));
        $this->line('Custom certificate: '.$this->describe($info['has_custom_certificate'] ?? null));
        $this->line('Last error date: '.$this->describe($info['last_error_date'] ?? null));

        return self::SUCCESS;
    }

    private function set(TelegramApiClient $telegram): int
    {
        $url = $this->option('url');
        $url = is_string($url) && trim($url) !== ''
            ? trim($url)
            : (string) config('telegram.webhook_url');

        if ($url === '') {
            throw TelegramNotConfigured::missingWebhookUrl();
        }

        if (! str_starts_with($url, 'https://')) {
            // Telegram requires HTTPS, and the secret would otherwise travel in
            // clear text on every delivery.
            $this->error('The Telegram webhook URL must be https.');

            return self::FAILURE;
        }

        $secret = config('telegram.webhook_secret');

        if (! is_string($secret) || trim($secret) === '') {
            throw TelegramNotConfigured::missingWebhookSecret();
        }

        $telegram->setWebhook($url, $secret);

        // The URL, because an operator needs to see where it went. Not the
        // secret that went with it.
        $this->info("Telegram webhook set to {$url}.");

        return self::SUCCESS;
    }

    private function delete(TelegramApiClient $telegram): int
    {
        $telegram->deleteWebhook();

        $this->info('Telegram webhook removed. The bot will receive no further updates.');

        return self::SUCCESS;
    }

    private function unknown(string $action): int
    {
        $this->error("Unknown action `{$action}`. Use info, set or delete.");

        return self::FAILURE;
    }

    private function describe(mixed $value): string
    {
        return match (true) {
            $value === null => '(none)',
            is_bool($value) => $value ? 'yes' : 'no',
            is_scalar($value) => (string) $value,
            default => '(not shown)',
        };
    }
}
