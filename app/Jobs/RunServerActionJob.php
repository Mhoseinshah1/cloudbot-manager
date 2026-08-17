<?php

namespace App\Jobs;

use App\Contracts\Data\ProviderImageData;
use App\Models\Server;
use App\Models\User;
use App\Services\ServerActionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class RunServerActionJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(
        public Server $server,
        public string $action,
        public ?User $actor = null,
        public ?ProviderImageData $image = null,
    ) {}

    public function handle(ServerActionService $service): void
    {
        $service->perform($this->server, $this->action, $this->actor, $this->image);
    }
}
