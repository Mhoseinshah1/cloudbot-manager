<?php

namespace App\Console\Commands\Telegram;

use App\Services\Telegram\TelegramApiClient;
use Illuminate\Console\Command;

class SetWebhookCommand extends Command
{
    protected $signature = 'telegram:set-webhook';

    protected $description = 'Set the Telegram webhook URL';

    public function handle(TelegramApiClient $api): int
    {
        $url = config('telegram.webhook_url');
        $secret = config('telegram.webhook_secret');

        if ($url === '' || $url === null) {
            $this->error('TELEGRAM_WEBHOOK_URL is not configured.');

            return self::FAILURE;
        }

        $result = $api->setWebhook($url, $secret ?: null);

        if ($result !== null) {
            $this->info('Webhook set successfully.');
            $this->info(json_encode($result, JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        $this->error('Failed to set webhook.');

        return self::FAILURE;
    }
}
