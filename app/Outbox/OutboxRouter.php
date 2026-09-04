<?php

declare(strict_types=1);

namespace App\Outbox;

use App\Jobs\ExecuteServerActionJob;
use App\Jobs\ProvisionOrderJob;
use App\Models\NotificationLog;
use App\Models\Order;
use App\Models\OutboxMessage;
use App\Models\Server;
use App\Models\ServerAction;
use App\Models\User;
use App\Notifications\CustomerMessages;
use App\Notifications\NotificationService;
use Illuminate\Support\Facades\Log;

/**
 * Decides what one delivered intent actually means.
 *
 * Two shapes of work reach here and they are deliberately different. Some
 * topics are messages to a person; others are work to schedule, and those send
 * nothing at all — `provisioning.requested` exists so that a paid order always
 * gets a provisioning job even if the process that took the money died before
 * dispatching one.
 *
 * Every payload is treated as a hint, not as truth. The ids are used to load
 * the current records from PostgreSQL and the message is built from those: an
 * outbox row written an hour ago describes an hour-old world, and a customer
 * should be told what is true now rather than what was true then.
 *
 * Nothing here executes a payload. A topic is matched against a closed list and
 * anything unrecognised is left unprocessed for a person to look at.
 */
final readonly class OutboxRouter
{
    public function __construct(private NotificationService $notifications) {}

    /**
     * Act on one message.
     *
     * @return bool Whether this message is now finished with. False leaves the
     *              row unprocessed, so the next sweep sees it again.
     */
    public function route(OutboxMessage $message): bool
    {
        return match ($message->topic) {
            OutboxTopic::ProvisioningRequested => $this->provisionOrder($message),
            OutboxTopic::ServerActionRequested => $this->executeServerAction($message),
            OutboxTopic::ProvisioningSucceeded => $this->provisioningSucceeded($message),
            OutboxTopic::OrderRefunded => $this->orderRefunded($message),
            OutboxTopic::ServerTerminated => $this->serverTerminated($message),
            OutboxTopic::ProvisioningNeedsAttention,
            OutboxTopic::ProvisioningFailed,
            OutboxTopic::InventoryDiscrepancy => $this->operationalAlert($message),
            default => $this->unknown($message),
        };
    }

    /**
     * A paid order that needs building.
     *
     * No message is sent. This is the durable half of "pay, then provision":
     * the job may be dispatched twice if this worker dies after dispatching and
     * before recording, and that is safe — one durable token yields one remote
     * machine however many jobs arrive.
     */
    private function provisionOrder(OutboxMessage $message): bool
    {
        $order = $this->orderFor($message);

        if (! $order instanceof Order) {
            return true;
        }

        ProvisionOrderJob::dispatch((int) $order->getKey());

        return true;
    }

    /**
     * Something a customer asked us to do to a server.
     *
     * Also no message. The action row already exists; this is what gets it onto
     * the worker that is allowed to call a provider.
     */
    private function executeServerAction(OutboxMessage $message): bool
    {
        $actionId = $this->intFrom($message, 'server_action_id');

        if ($actionId === null) {
            return true;
        }

        $action = ServerAction::query()->whereKey($actionId)->first();

        if (! $action instanceof ServerAction) {
            return true;
        }

        if (! $action->isOpen()) {
            // Already settled, by a reconciler or an earlier delivery.
            return true;
        }

        ExecuteServerActionJob::dispatch((int) $action->getKey());

        return true;
    }

    private function provisioningSucceeded(OutboxMessage $message): bool
    {
        $order = $this->orderFor($message);

        if (! $order instanceof Order) {
            return true;
        }

        $customer = User::query()->whereKey($order->user_id)->first();

        if (! $customer instanceof User) {
            return true;
        }

        // Loaded now, not read out of the payload. The server's address may
        // have been corrected by reconciliation since the intent was written.
        $server = Server::query()->where('order_id', $order->getKey())->first();

        $facts = [
            'order_number' => $order->order_number,
            'server_name' => $server?->name,
            'ip_address' => $server?->ip_address,
            'ipv6_address' => $server?->ipv6_address,
            'current_period_end' => $server?->subscription?->current_period_end?->toDateString(),
        ];

        $this->notifications->toCustomer(
            $customer,
            OutboxTopic::ProvisioningSucceeded,
            CustomerMessages::provisioningSucceeded($facts),
            ['order_id' => $order->getKey(), 'order_number' => $order->order_number, 'server_id' => $server?->getKey()],
            (int) $message->getKey(),
            self::deliveryKey($message),
        );

        return true;
    }

    private function orderRefunded(OutboxMessage $message): bool
    {
        $order = $this->orderFor($message);

        if (! $order instanceof Order) {
            return true;
        }

        $customer = User::query()->whereKey($order->user_id)->first();

        if (! $customer instanceof User) {
            return true;
        }

        $this->notifications->toCustomer(
            $customer,
            OutboxTopic::OrderRefunded,
            CustomerMessages::orderRefunded([
                'order_number' => $order->order_number,
                'amount_toman' => $this->intFrom($message, 'amount_toman') ?? $order->total_toman,
            ]),
            ['order_id' => $order->getKey(), 'order_number' => $order->order_number],
            (int) $message->getKey(),
            self::deliveryKey($message),
        );

        return true;
    }

    private function serverTerminated(OutboxMessage $message): bool
    {
        $serverId = $this->intFrom($message, 'server_id');
        $server = $serverId === null ? null : Server::query()->whereKey($serverId)->first();

        if (! $server instanceof Server) {
            return true;
        }

        $customer = User::query()->whereKey($server->user_id)->first();

        if (! $customer instanceof User) {
            return true;
        }

        $this->notifications->toCustomer(
            $customer,
            OutboxTopic::ServerTerminated,
            CustomerMessages::serverTerminated(['server_name' => $server->name]),
            ['server_id' => $server->getKey(), 'order_id' => $server->order_id],
            (int) $message->getKey(),
            self::deliveryKey($message),
        );

        return true;
    }

    /**
     * Something an operator has to know about.
     *
     * The text is identifiers and a topic. A provider's own error message
     * quotes back the request that caused it, and the request carries
     * credentials — so none of it is copied into an alert, however useful it
     * would be to read.
     */
    private function operationalAlert(OutboxMessage $message): bool
    {
        $summary = $this->safeSummary($message);

        $lines = ['⚠️ '.$message->topic, ''];

        foreach ($summary as $key => $value) {
            $lines[] = $key.': '.$value;
        }

        $this->notifications->toAdministrators(
            $message->topic,
            implode("\n", $lines),
            $summary,
            (int) $message->getKey(),
            self::deliveryKey($message),
        );

        return true;
    }

    /**
     * A topic nothing knows how to deliver.
     *
     * Left unprocessed deliberately. A row that stays visible is a question
     * somebody can answer; one that was marked done is a message that silently
     * never arrived.
     */
    private function unknown(OutboxMessage $message): bool
    {
        Log::warning('outbox.unknown_topic', [
            'outbox_message_id' => $message->getKey(),
            'topic' => $message->topic,
        ]);

        return false;
    }

    /** One delivery per intent per channel, however many workers arrive. */
    public static function deliveryKey(OutboxMessage $message): string
    {
        return 'outbox:'.$message->getKey().':delivered';
    }

    private function orderFor(OutboxMessage $message): ?Order
    {
        $orderId = $this->intFrom($message, 'order_id');

        if ($orderId === null) {
            return null;
        }

        $order = Order::query()->whereKey($orderId)->first();

        return $order instanceof Order ? $order : null;
    }

    private function intFrom(OutboxMessage $message, string $key): ?int
    {
        $value = $message->payload[$key] ?? null;

        if (is_int($value)) {
            return $value;
        }

        return is_string($value) && preg_match('/^\d+$/', $value) === 1 ? (int) $value : null;
    }

    /**
     * Scalars from the payload, bounded, for an operator to read.
     *
     * @return array<string, string>
     */
    private function safeSummary(OutboxMessage $message): array
    {
        $summary = [];

        foreach ($message->payload as $key => $value) {
            if (! is_string($key) || count($summary) >= 12) {
                continue;
            }

            if (is_int($value) || is_bool($value)) {
                $summary[$key] = is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;

                continue;
            }

            if (is_string($value)) {
                $summary[$key] = mb_substr($value, 0, 120);
            }
        }

        return $summary;
    }

    /** Whether this intent has already been delivered on its channel. */
    public function alreadyDelivered(OutboxMessage $message): bool
    {
        return NotificationLog::query()
            ->where('deduplication_key', self::deliveryKey($message))
            ->exists();
    }
}
