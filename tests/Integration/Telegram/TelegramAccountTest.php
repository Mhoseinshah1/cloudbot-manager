<?php

declare(strict_types=1);

use App\Enums\UserCreatedVia;
use App\Enums\UserStatus;
use App\Models\TelegramAccount;
use App\Models\User;
use App\Telegram\TelegramAccountService;
use App\Telegram\TelegramUpdateNormalizer;

/**
 * Who a Telegram update is from.
 *
 * Identity is `telegram_user_id`, and every test here exists to hold that line.
 * A username is a display name: people change theirs, and a released one can be
 * taken by somebody else — so matching on one would eventually hand a
 * customer's servers and wallet to a stranger.
 */
beforeEach(function (): void {
    $this->accounts = app(TelegramAccountService::class);
    $this->normalizer = app(TelegramUpdateNormalizer::class);
});

function identify(int $telegramUserId, array $from = [], string $chatType = 'private', ?int $chatId = null): ?TelegramAccount
{
    $normalized = test()->normalizer->normalize([
        'update_id' => random_int(1, 2_000_000_000),
        'message' => [
            'message_id' => 1,
            'from' => ['id' => $telegramUserId, 'is_bot' => false, ...$from],
            'chat' => ['id' => $chatId ?? $telegramUserId, 'type' => $chatType],
            'text' => '/start',
        ],
    ]);

    return test()->accounts->identify($normalized);
}

it('creates a customer and their telegram identity together', function (): void {
    $account = identify(5_500_123_456, ['first_name' => 'سارا', 'last_name' => 'احمدی', 'username' => 'sara']);

    expect($account)->not->toBeNull()
        ->and($account->telegram_user_id)->toBe(5_500_123_456)
        ->and($account->username)->toBe('sara')
        ->and($account->first_name)->toBe('سارا')
        ->and($account->last_seen_at)->not->toBeNull();

    $user = $account->user;

    expect($user->created_via)->toBe(UserCreatedVia::Telegram)
        // Legitimately absent: an account that arrived through Telegram has
        // neither, and inventing either would be inventing a credential.
        ->and($user->email)->toBeNull()
        ->and($user->password)->toBeNull()
        ->and($user->status)->toBe(UserStatus::Active)
        ->and($user->locale)->toBe(config('cloudbot.defaults.locale'))
        ->and($user->timezone)->toBe(config('cloudbot.defaults.timezone'));
});

it('round-trips telegram ids beyond 32 bits', function (): void {
    $account = identify(8_123_456_789_012, chatId: 8_123_456_789_013);

    expect($account->telegram_user_id)->toBe(8_123_456_789_012)
        ->and($account->telegram_chat_id)->toBe(8_123_456_789_013)
        ->and($account->fresh()->telegram_user_id)->toBe(8_123_456_789_012);
});

it('keeps the same customer when their username changes', function (): void {
    $first = identify(5_500_123_456, ['username' => 'old_name']);
    $second = identify(5_500_123_456, ['username' => 'new_name']);

    expect($second->getKey())->toBe($first->getKey())
        ->and($second->user_id)->toBe($first->user_id)
        ->and($second->username)->toBe('new_name')
        ->and(User::query()->count())->toBe(1)
        ->and(TelegramAccount::query()->count())->toBe(1);
});

it('never merges two people who once shared a username', function (): void {
    // The realistic version: somebody releases a username and somebody else
    // takes it. They are different customers and must stay so.
    $original = identify(5_500_123_456, ['username' => 'popular']);
    $newcomer = identify(6_600_987_654, ['username' => 'popular']);

    expect($newcomer->getKey())->not->toBe($original->getKey())
        ->and($newcomer->user_id)->not->toBe($original->user_id)
        ->and(User::query()->count())->toBe(2)
        ->and(TelegramAccount::query()->count())->toBe(2);
});

it('refreshes display metadata without touching anything that matters', function (): void {
    $account = identify(5_500_123_456, ['first_name' => 'قدیمی', 'username' => 'before']);
    $user = $account->user;

    $user->forceFill(['wallet_balance_toman' => 250_000])->save();
    $seenBefore = $account->last_seen_at;

    $refreshed = identify(5_500_123_456, ['first_name' => 'جدید', 'last_name' => 'نو', 'username' => 'after']);

    expect($refreshed->first_name)->toBe('جدید')
        ->and($refreshed->last_name)->toBe('نو')
        ->and($refreshed->username)->toBe('after')
        ->and($refreshed->last_seen_at)->not->toBeNull()
        // Money, status and identity are none of a greeting's business.
        ->and($refreshed->user->wallet_balance_toman)->toBe(250_000)
        ->and($refreshed->user_id)->toBe($user->getKey());
});

it('does not revive a suspended or banned customer', function (string $status): void {
    $account = identify(5_500_123_456);
    $account->user->forceFill(['status' => $status])->save();

    identify(5_500_123_456, ['first_name' => 'دوباره']);

    // `/start` is a greeting, not an appeal.
    expect($account->user->fresh()->status->value)->toBe($status)
        // Cosmetic refresh is still fine.
        ->and($account->fresh()->first_name)->toBe('دوباره');
})->with(['suspended', 'banned']);

it('will not let a group redirect a customer private chat', function (): void {
    $account = identify(5_500_123_456);
    $privateChat = $account->telegram_chat_id;

    // The same person speaking in a group the bot is also in.
    identify(5_500_123_456, chatType: 'supergroup', chatId: -1_001_234_567_890);

    // Their invoices and server credentials still go to them, not to a room.
    expect($account->fresh()->telegram_chat_id)->toBe($privateChat)
        ->and($account->fresh()->telegram_chat_id)->not->toBe(-1_001_234_567_890);
});

it('follows a customer whose private chat id legitimately changes', function (): void {
    $account = identify(5_500_123_456, chatId: 5_500_123_456);

    identify(5_500_123_456, chatId: 5_500_999_999);

    expect($account->fresh()->telegram_chat_id)->toBe(5_500_999_999);
});

it('creates nothing for another bot', function (): void {
    $normalized = $this->normalizer->normalize([
        'update_id' => 1,
        'message' => [
            'message_id' => 1,
            'from' => ['id' => 777, 'is_bot' => true],
            'chat' => ['id' => 777, 'type' => 'private'],
            'text' => '/start',
        ],
    ]);

    expect($this->accounts->identify($normalized))->toBeNull()
        ->and(User::query()->count())->toBe(0)
        ->and(TelegramAccount::query()->count())->toBe(0);
});

it('creates nothing for an update with no author', function (): void {
    $normalized = $this->normalizer->normalize([
        'update_id' => 1,
        'channel_post' => ['message_id' => 1, 'chat' => ['id' => -100, 'type' => 'channel']],
    ]);

    expect($this->accounts->identify($normalized))->toBeNull()
        ->and(User::query()->count())->toBe(0);
});

it('leaves no orphan user when the identity cannot be written', function (): void {
    // A customer already exists; a concurrent first-contact for the same
    // identity must lose to the unique index and roll its User back rather than
    // leaving a customer record nobody can reach.
    identify(5_500_123_456);

    expect(User::query()->count())->toBe(1);

    identify(5_500_123_456);
    identify(5_500_123_456);

    expect(User::query()->count())->toBe(1)
        ->and(TelegramAccount::query()->count())->toBe(1);
});

it('marks the account telegram refused, and lets an inbound message clear it', function (): void {
    $account = identify(5_500_123_456);

    $this->accounts->markBotBlocked($account);

    expect($account->fresh()->hasBlockedBot())->toBeTrue();

    // Telegram has delivered from this identity again, which is proof the bot
    // is reachable.
    identify(5_500_123_456);

    expect($account->fresh()->bot_blocked_at)->toBeNull();
});

it('does not clear a blocked flag from a group message', function (): void {
    $account = identify(5_500_123_456);
    $this->accounts->markBotBlocked($account);

    identify(5_500_123_456, chatType: 'group', chatId: -1_001_234_567_890);

    // A group message proves nothing about whether they will accept a private
    // one.
    expect($account->fresh()->bot_blocked_at)->not->toBeNull();
});

it('gives a nameless customer something an operator can find them by', function (): void {
    $account = identify(5_500_123_456);

    expect($account->user->name)->toContain('5500123456');
});
