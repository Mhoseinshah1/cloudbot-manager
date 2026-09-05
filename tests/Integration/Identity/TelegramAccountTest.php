<?php

declare(strict_types=1);

use App\Models\TelegramAccount;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Schema;

it('links a telegram identity to a user', function (): void {
    $user = User::factory()->fromTelegram()->create();
    $account = TelegramAccount::factory()->create(['user_id' => $user->id]);

    expect($account->user->is($user))->toBeTrue()
        ->and($user->telegramAccounts()->count())->toBe(1)
        ->and($user->telegramAccounts->first()->is($account))->toBeTrue();
});

it('stores telegram ids beyond the 32-bit range', function (): void {
    // Real Telegram user ids already exceed 2^31.
    $account = TelegramAccount::factory()->create([
        'telegram_user_id' => 7_999_999_999,
        'telegram_chat_id' => 8_123_456_789,
    ]);

    $stored = $account->fresh();

    expect($stored->telegram_user_id)->toBe(7_999_999_999)
        ->and($stored->telegram_chat_id)->toBe(8_123_456_789);
});

it('rejects a duplicate telegram user id', function (): void {
    // This is the identity, so the database must enforce it rather than
    // trusting whichever code path happens to insert next.
    TelegramAccount::factory()->create(['telegram_user_id' => 5_555_555_555]);

    expect(fn () => TelegramAccount::factory()->create(['telegram_user_id' => 5_555_555_555]))
        ->toThrow(QueryException::class);
});

it('allows the same username on different accounts', function (): void {
    // Usernames are display metadata: people change them, and a released one
    // can be taken by someone else. Nothing may key off them.
    TelegramAccount::factory()->create(['username' => 'reused_handle']);
    $second = TelegramAccount::factory()->create(['username' => 'reused_handle']);

    expect($second->exists)->toBeTrue()
        ->and(TelegramAccount::query()->where('username', 'reused_handle')->count())->toBe(2);
});

it('allows a user to hold more than one telegram account', function (): void {
    // The specification does not require one per user, so nothing enforces it.
    $user = User::factory()->fromTelegram()->create();

    TelegramAccount::factory()->count(2)->create(['user_id' => $user->id]);

    expect($user->telegramAccounts()->count())->toBe(2);
});

it('records that a user blocked the bot', function (): void {
    expect(TelegramAccount::factory()->blocked()->create()->hasBlockedBot())->toBeTrue()
        ->and(TelegramAccount::factory()->create()->hasBlockedBot())->toBeFalse();
});

it('has no is_verified flag', function (): void {
    expect(Schema::hasColumn('telegram_accounts', 'is_verified'))->toBeFalse();
});

it('refuses to delete a user who still has a telegram identity', function (): void {
    // Accounts with history are never hard-deleted; this makes an attempt fail
    // loudly instead of quietly removing the identity behind it.
    $user = User::factory()->fromTelegram()->create();
    TelegramAccount::factory()->create(['user_id' => $user->id]);

    expect(fn () => $user->delete())->toThrow(QueryException::class);
});
