<?php

namespace App\Console\Commands\Telegram;

use App\Services\Telegram\TelegramApiClient;
use Illuminate\Console\Command;

class DeleteWebhookCommand extends Command
{
    protected $signature = 'telegram:delete-webhook';

    protected $description = 'Delete the Telegram webhook';

    public function handle(TelegramApiClient $api): int
    {
        $result = $api->deleteWebhook();

        if ($result !== null) {
            $this->info('Webhook deleted successfully.');

            return self::SUCCESS;
        }

        $this->error('Failed to delete webhook.');

        return self::FAILURE;
    }
}
