<?php

namespace App\Jobs;

use App\Services\Telegram\TelegramUpdateRouter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Throwable;

class ProcessTelegramUpdateJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 5;

    /**
     * @param  array<string, mixed>  $update
     */
    public function __construct(public array $update) {}

    public function handle(TelegramUpdateRouter $router): void
    {
        $router->handle($this->update);
    }

    public function failed(Throwable $exception): void
    {
        if (isset($this->update['update_id'])) {
            Cache::forget('tg-update:'.$this->update['update_id']);
        }
    }
}
