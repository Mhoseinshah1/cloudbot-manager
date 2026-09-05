<?php

declare(strict_types=1);

namespace App\Telegram;

use App\Support\Secrets\SecretScrubber;
use App\Telegram\Callbacks\CallbackGrammar;
use App\Telegram\Data\NormalizedUpdate;
use App\Telegram\Enums\TelegramAction;
use App\Telegram\Enums\TelegramChatType;
use App\Telegram\Enums\TelegramUpdateType;

/**
 * Turns an arbitrary Telegram payload into the handful of facts this system
 * is willing to keep.
 *
 * This is the trust boundary. Everything above it is a JSON document composed
 * by whoever sent the message: unbounded text, arbitrary keys, deliberately
 * hostile content. Everything below it is numbers and closed-enum values.
 *
 * The rule that makes that work is that nothing is copied through. Each field
 * is asked for by name, checked for type, and bounded; message text is not
 * stored at all, only what it was recognised as. A message nobody has a handler
 * for becomes `Unknown`, and its content is discarded rather than kept in case
 * it is useful later — text kept "in case" is exactly what ends up rendered on
 * an operator's screen.
 *
 * Callback data now carries parameters — which server, which page — and is
 * parsed by {@see CallbackGrammar} rather than matched whole. That does not
 * relax the rule: the grammar is closed, every field is bounded, and what
 * survives is a handful of scalars this system parsed rather than the string a
 * customer sent. None of it is authority. An id says what somebody asked
 * about, and whether they may have it is decided by a query scoped to them.
 */
final readonly class TelegramUpdateNormalizer
{
    /** Comfortably inside the column, and far inside anything Telegram sends. */
    private const MAX_PROFILE_FIELD = 120;

    /** The customer-facing labels this phase recognises. */
    private const MENU_LABELS = [
        'خرید سرور' => TelegramAction::MenuBuyServer,
        'سرورهای من' => TelegramAction::MenuMyServers,
        'کیف پول' => TelegramAction::MenuWallet,
        'فاکتورها' => TelegramAction::MenuInvoices,
        'پروفایل' => TelegramAction::MenuProfile,
        'راهنما' => TelegramAction::MenuHelp,
        'منوی اصلی' => TelegramAction::MainMenu,
    ];

    /**
     * @param  array<string, mixed>  $payload
     */
    public function normalize(array $payload): ?NormalizedUpdate
    {
        $updateId = $payload['update_id'] ?? null;

        // Without an id there is nothing to deduplicate on, so there is no safe
        // way to handle it even once.
        if (! is_int($updateId)) {
            return null;
        }

        $callback = $this->arrayAt($payload, 'callback_query');

        if ($callback !== null) {
            return $this->fromCallbackQuery($updateId, $callback);
        }

        $message = $this->arrayAt($payload, 'message') ?? $this->arrayAt($payload, 'edited_message');

        if ($message !== null) {
            return $this->fromMessage($updateId, $message);
        }

        // A kind nothing is written for. Recorded so Telegram stops retrying
        // it, and otherwise ignored.
        return new NormalizedUpdate(
            updateId: $updateId,
            type: TelegramUpdateType::Other,
            chatType: TelegramChatType::Unknown,
            telegramUserId: null,
            telegramChatId: null,
            messageId: null,
            callbackQueryId: null,
            action: TelegramAction::Unknown,
        );
    }

    /**
     * @param  array<string, mixed>  $message
     */
    private function fromMessage(int $updateId, array $message): NormalizedUpdate
    {
        $from = $this->arrayAt($message, 'from') ?? [];
        $chat = $this->arrayAt($message, 'chat') ?? [];

        return new NormalizedUpdate(
            updateId: $updateId,
            type: TelegramUpdateType::Message,
            chatType: TelegramChatType::fromTelegram($this->stringAt($chat, 'type')),
            telegramUserId: $this->intAt($from, 'id'),
            telegramChatId: $this->intAt($chat, 'id'),
            messageId: $this->intAt($message, 'message_id'),
            callbackQueryId: null,
            action: $this->actionFromText($this->stringAt($message, 'text')),
            profile: $this->profile($from),
            isBot: ($from['is_bot'] ?? false) === true,
        );
    }

    /**
     * @param  array<string, mixed>  $callback
     */
    private function fromCallbackQuery(int $updateId, array $callback): NormalizedUpdate
    {
        $from = $this->arrayAt($callback, 'from') ?? [];
        $message = $this->arrayAt($callback, 'message') ?? [];
        $chat = $this->arrayAt($message, 'chat') ?? [];

        // Parsed, not stored. What survives is the action and a handful of
        // bounded scalars; the opaque string the customer sent does not.
        $parsed = CallbackGrammar::parse($this->stringAt($callback, 'data'));

        return new NormalizedUpdate(
            updateId: $updateId,
            type: TelegramUpdateType::CallbackQuery,
            chatType: TelegramChatType::fromTelegram($this->stringAt($chat, 'type')),
            telegramUserId: $this->intAt($from, 'id'),
            telegramChatId: $this->intAt($chat, 'id'),
            messageId: $this->intAt($message, 'message_id'),
            // Telegram's handle for the pressed button. Bounded, and kept only
            // long enough to stop the spinner.
            callbackQueryId: $this->bounded($this->stringAt($callback, 'id'), 64),
            action: $parsed['action'],
            profile: $this->profile($from),
            isBot: ($from['is_bot'] ?? false) === true,
            parameters: $parsed['parameters'],
        );
    }

    /**
     * What a message asked for, if anything.
     *
     * The text itself never leaves this method. `/start` may arrive with a deep
     * link payload attached, which is why the command is compared against the
     * first token rather than the whole string.
     */
    private function actionFromText(?string $text): TelegramAction
    {
        if ($text === null) {
            return TelegramAction::Unknown;
        }

        $trimmed = trim($text);
        $command = strtolower((string) strtok($trimmed, ' '));

        // `/start@thisbot` in a group is still /start.
        $command = (string) strtok($command, '@');

        if ($command === '/start') {
            return TelegramAction::Start;
        }

        if ($command === '/menu') {
            return TelegramAction::MainMenu;
        }

        if ($command === '/help') {
            return TelegramAction::MenuHelp;
        }

        return self::MENU_LABELS[$trimmed] ?? TelegramAction::Unknown;
    }

    /**
     * Display metadata, and only display metadata.
     *
     * Three named fields, bounded and scrubbed. Not the `from` object: that
     * grows new keys whenever Telegram adds a feature, and a whitelist that is
     * written out by hand is the only kind that stays one.
     *
     * @param  array<string, mixed>  $from
     * @return array<string, scalar|null>
     */
    private function profile(array $from): array
    {
        return [
            'username' => $this->bounded($this->stringAt($from, 'username'), self::MAX_PROFILE_FIELD),
            'first_name' => $this->bounded($this->stringAt($from, 'first_name'), self::MAX_PROFILE_FIELD),
            'last_name' => $this->bounded($this->stringAt($from, 'last_name'), self::MAX_PROFILE_FIELD),
        ];
    }

    private function bounded(?string $value, int $length): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim($value);

        if ($trimmed === '') {
            return null;
        }

        // Scrubbed as well as bounded. A display name is free text somebody
        // else chose, and it ends up in logs and operator screens.
        return SecretScrubber::scrubText(mb_substr($trimmed, 0, $length));
    }

    /**
     * @param  array<string, mixed>  $source
     * @return array<string, mixed>|null
     */
    private function arrayAt(array $source, string $key): ?array
    {
        $value = $source[$key] ?? null;

        return is_array($value) ? $value : null;
    }

    /**
     * @param  array<string, mixed>  $source
     */
    private function stringAt(array $source, string $key): ?string
    {
        $value = $source[$key] ?? null;

        return is_string($value) ? $value : null;
    }

    /**
     * @param  array<string, mixed>  $source
     */
    private function intAt(array $source, string $key): ?int
    {
        $value = $source[$key] ?? null;

        return is_int($value) ? $value : null;
    }
}
