<?php

declare(strict_types=1);

namespace Tests\Support\Telegram;

use App\Enums\SettingKey;
use App\Jobs\ProcessTelegramUpdateJob;
use App\Models\TelegramAccount;
use App\Models\TelegramUpdate;
use App\Models\User;
use App\Settings\SettingsService;
use App\Telegram\TelegramUpdateNormalizer;
use App\Telegram\TelegramUpdateRecorder;
use Illuminate\Support\Facades\Http;
use Tests\Support\Provisioning\ProvisioningFloor;

/**
 * A shop with a bot in front of it, and a customer talking to it.
 *
 * Drives updates through the real pipeline — normalize, record, run the job —
 * rather than calling flows directly. That is the point: the deduplication,
 * the locks and the token checks are all in that pipeline, and a test that
 * stepped around them would prove the flow works while proving nothing about
 * whether a duplicate delivery is safe.
 */
final class BotFloor
{
    public const TELEGRAM_USER_ID = 5_500_123_456;

    public ProvisioningFloor $shop;

    public TelegramAccount $account;

    private int $nextUpdateId = 900_000;

    private function __construct() {}

    public static function open(int $walletBalance = 5_000_000, int $sellingPriceToman = 1_500_000): self
    {
        $self = new self;

        config()->set('telegram.api_base_url', 'https://api.telegram.test');
        // Generated at runtime, never committed: a credential-shaped literal in
        // the repository is a secret-scanner finding whether or not it is real.
        config()->set('telegram.bot_token', '11'.random_int(1_000_000, 9_999_999).':AA'.bin2hex(random_bytes(12)));

        Http::preventStrayRequests();

        $self->shop = ProvisioningFloor::open($walletBalance, $sellingPriceToman);

        $self->account = TelegramAccount::query()->create([
            'user_id' => $self->shop->customer->getKey(),
            'telegram_user_id' => self::TELEGRAM_USER_ID,
            'telegram_chat_id' => self::TELEGRAM_USER_ID,
            'first_name' => 'مریم',
        ]);

        return $self;
    }

    public function customer(): User
    {
        return $this->shop->customer->fresh() ?? $this->shop->customer;
    }

    /** Set one of the abuse ceilings to something a test can reach. */
    public function setLimit(SettingKey $key, ?int $value): void
    {
        app(SettingsService::class)->set($key, $value, $this->shop->owner);
    }

    public function setAupVersion(string $version): void
    {
        app(SettingsService::class)->set(SettingKey::AupCurrentVersion, $version, $this->shop->owner);
    }

    /** A customer typing a menu label or a command. */
    public function say(string $text, ?int $updateId = null): TelegramUpdate
    {
        return $this->deliver([
            'update_id' => $updateId ?? $this->nextUpdateId(),
            'message' => [
                'message_id' => random_int(1, 100_000),
                'from' => ['id' => self::TELEGRAM_USER_ID, 'is_bot' => false, 'first_name' => 'مریم'],
                'chat' => ['id' => self::TELEGRAM_USER_ID, 'type' => 'private'],
                'text' => $text,
            ],
        ]);
    }

    /** A customer pressing an inline button. */
    public function tap(string $callbackData, ?int $updateId = null): TelegramUpdate
    {
        return $this->deliver([
            'update_id' => $updateId ?? $this->nextUpdateId(),
            'callback_query' => [
                'id' => (string) random_int(1, 1_000_000_000),
                'from' => ['id' => self::TELEGRAM_USER_ID, 'is_bot' => false, 'first_name' => 'مریم'],
                'message' => [
                    'message_id' => random_int(1, 100_000),
                    'chat' => ['id' => self::TELEGRAM_USER_ID, 'type' => 'private'],
                ],
                'data' => $callbackData,
            ],
        ]);
    }

    /**
     * Record an update and run its job, the way the webhook and worker would.
     *
     * @param  array<string, mixed>  $payload
     */
    public function deliver(array $payload): TelegramUpdate
    {
        $normalized = app(TelegramUpdateNormalizer::class)->normalize($payload);
        $update = app(TelegramUpdateRecorder::class)->record($normalized)['update'];

        $this->run($update);

        return $update->fresh() ?? $update;
    }

    /** Run one already-recorded update again, as a redelivery would. */
    public function run(TelegramUpdate $update): void
    {
        app()->call([new ProcessTelegramUpdateJob((int) $update->getKey()), 'handle']);
    }

    /**
     * Every callback_data this system has drawn on a keyboard so far.
     *
     * Read out of what was actually sent, so a test follows the buttons the bot
     * offered rather than constructing the ones it hopes exist.
     *
     * @return list<string>
     */
    public static function buttonsSent(): array
    {
        $found = [];

        foreach (Http::recorded() as $exchange) {
            $markup = $exchange[0]['reply_markup'] ?? null;

            if (! is_string($markup)) {
                continue;
            }

            $decoded = json_decode($markup, true);

            if (! is_array($decoded)) {
                continue;
            }

            foreach ($decoded['inline_keyboard'] ?? [] as $row) {
                foreach (is_array($row) ? $row : [] as $button) {
                    if (is_array($button) && is_string($button['callback_data'] ?? null)) {
                        $found[] = $button['callback_data'];
                    }
                }
            }
        }

        return $found;
    }

    /** The most recent button whose data starts with this prefix. */
    public static function lastButton(string $prefix): ?string
    {
        $matching = array_values(array_filter(
            self::buttonsSent(),
            static fn (string $data): bool => str_starts_with($data, $prefix),
        ));

        return $matching === [] ? null : $matching[array_key_last($matching)];
    }

    /** Everything the bot has said, concatenated. */
    public static function transcript(): string
    {
        $text = '';

        foreach (Http::recorded() as $exchange) {
            $body = $exchange[0]['text'] ?? null;

            if (is_string($body)) {
                $text .= $body."\n";
            }
        }

        return $text;
    }

    public function nextUpdateId(): int
    {
        return $this->nextUpdateId++;
    }
}
