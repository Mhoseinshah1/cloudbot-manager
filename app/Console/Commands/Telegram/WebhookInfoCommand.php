<?php

namespace App\Console\Commands\Telegram;

use App\Services\Telegram\TelegramApiClient;
use Illuminate\Console\Command;

class WebhookInfoCommand extends Command
{
    protected $signature = 'telegram:webhook-info';

    protected $description = 'Get Telegram webhook information';

    public function handle(TelegramApiClient $api): int
    {
        $result = $api->getWebhookInfo();

        if ($result !== null) {
            $this->info(json_encode($result, JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        $this->error('Failed to get webhook info.');

        return self::FAILURE;
    }
}
