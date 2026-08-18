<?php

namespace App\Events;

use App\Models\LowBalanceWarning;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Emitted whenever a new low-balance warning record is created.
 *
 * This is the provider-independent hook that future Telegram/Mini App
 * notification handlers will listen to. The platform never sends messages
 * itself; it only records state and dispatches domain events.
 */
class LowBalanceWarningTriggered
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public LowBalanceWarning $warning) {}
}
