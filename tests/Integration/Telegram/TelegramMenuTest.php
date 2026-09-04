<?php

declare(strict_types=1);

use App\Enums\UserStatus;
use App\Telegram\Enums\TelegramAction;
use App\Telegram\MainMenu;
use App\Telegram\TelegramUpdateNormalizer;

/**
 * What the customer sees, and how their words become an action.
 *
 * The normalizer is the trust boundary: everything above it is arbitrary text
 * from a stranger, everything below is a value drawn from a closed list. These
 * tests hold that line.
 */
beforeEach(function (): void {
    $this->normalizer = app(TelegramUpdateNormalizer::class);
});

function actionOf(string $text): TelegramAction
{
    return test()->normalizer->normalize([
        'update_id' => 1,
        'message' => [
            'message_id' => 1,
            'from' => ['id' => 5_500_123_456, 'is_bot' => false],
            'chat' => ['id' => 5_500_123_456, 'type' => 'private'],
            'text' => $text,
        ],
    ])->action;
}

it('shows the six release 1.0 entries, in Persian', function (): void {
    $labels = collect(MainMenu::keyboard()['keyboard'])
        ->flatten(1)
        ->pluck('text')
        ->all();

    expect($labels)->toBe([
        'خرید سرور', 'سرورهای من', 'کیف پول', 'فاکتورها', 'پروفایل', 'راهنما',
    ]);
});

it('recognises every label it renders', function (): void {
    $labels = collect(MainMenu::keyboard()['keyboard'])->flatten(1)->pluck('text');

    // A button the normalizer cannot read is a button that silently does
    // nothing, which is worse than not showing it.
    $labels->each(fn (string $label) => expect(actionOf($label))->not->toBe(TelegramAction::Unknown));
});

it('reads the commands a customer might type', function (string $text, TelegramAction $expected): void {
    expect(actionOf($text))->toBe($expected);
})->with([
    'start' => ['/start', TelegramAction::Start],
    'start with a deep link' => ['/start ref_abc123', TelegramAction::Start],
    'start addressed to the bot' => ['/start@cloudbot', TelegramAction::Start],
    'menu' => ['/menu', TelegramAction::MainMenu],
    'help' => ['/help', TelegramAction::MenuHelp],
    'a label' => ['خرید سرور', TelegramAction::MenuBuyServer],
    'anything else' => ['can you build me a server please', TelegramAction::Unknown],
    'an empty message' => ['', TelegramAction::Unknown],
    'something hostile' => ['<script>alert(1)</script>', TelegramAction::Unknown],
]);

it('reads only the callback forms it defines', function (?string $data, TelegramAction $expected): void {
    $normalized = $this->normalizer->normalize([
        'update_id' => 1,
        'callback_query' => [
            'id' => 'cbq-1',
            'from' => ['id' => 5_500_123_456, 'is_bot' => false],
            'message' => ['message_id' => 1, 'chat' => ['id' => 5_500_123_456, 'type' => 'private']],
            'data' => $data,
        ],
    ]);

    expect($normalized->action)->toBe($expected);
})->with([
    'a known form' => ['menu:help', TelegramAction::MenuHelp],
    'another known form' => ['menu:main', TelegramAction::MainMenu],
    // Nothing about ownership, price or authority is ever read out of callback
    // data. A convincing-looking one means nothing.
    'a forged instruction' => ['order:99:confirm:pay', TelegramAction::Unknown],
    'somebody elses server' => ['server:1:delete', TelegramAction::Unknown],
    'absent' => [null, TelegramAction::Unknown],
]);

it('ignores callback data longer than telegram allows', function (): void {
    $normalized = $this->normalizer->normalize([
        'update_id' => 1,
        'callback_query' => [
            'id' => 'cbq-1',
            'from' => ['id' => 5_500_123_456, 'is_bot' => false],
            'message' => ['message_id' => 1, 'chat' => ['id' => 5_500_123_456, 'type' => 'private']],
            'data' => str_repeat('a', 500),
        ],
    ]);

    expect($normalized->action)->toBe(TelegramAction::Unknown);
});

it('keeps only three named profile fields, bounded', function (): void {
    $normalized = $this->normalizer->normalize([
        'update_id' => 1,
        'message' => [
            'message_id' => 1,
            'from' => [
                'id' => 5_500_123_456,
                'is_bot' => false,
                'first_name' => str_repeat('ن', 400),
                'username' => 'someone',
                'last_name' => 'زاده',
                // Telegram grows new keys whenever it adds a feature; a
                // whitelist written by hand is the only kind that stays one.
                'language_code' => 'fa',
                'is_premium' => true,
                'can_join_groups' => true,
            ],
            'chat' => ['id' => 5_500_123_456, 'type' => 'private'],
            'text' => '/start',
        ],
    ]);

    expect(array_keys($normalized->profile))->toBe(['username', 'first_name', 'last_name'])
        ->and(mb_strlen((string) $normalized->profile['first_name']))->toBe(120)
        ->and($normalized->metadata())->not->toHaveKey('language_code')
        ->and($normalized->metadata())->not->toHaveKey('is_premium');
});

it('scrubs a display name that carries a credential', function (): void {
    $token = '77'.random_int(10_000_000, 99_999_999).':AA'.bin2hex(random_bytes(16));

    $normalized = $this->normalizer->normalize([
        'update_id' => 1,
        'message' => [
            'message_id' => 1,
            'from' => ['id' => 5_500_123_456, 'is_bot' => false, 'first_name' => "bot{$token}"],
            'chat' => ['id' => 5_500_123_456, 'type' => 'private'],
            'text' => '/start',
        ],
    ]);

    expect($normalized->profile['first_name'])->not->toContain($token)
        ->and($normalized->profile['first_name'])->toContain('[redacted]');
});

it('refuses an update with no id at all', function (): void {
    // Nothing to deduplicate on means no safe way to handle it even once.
    expect($this->normalizer->normalize(['message' => ['text' => '/start']]))->toBeNull()
        ->and($this->normalizer->normalize([]))->toBeNull()
        ->and($this->normalizer->normalize(['update_id' => 'not-a-number']))->toBeNull();
});

it('says nothing about phases to a customer', function (): void {
    $customerFacing = [
        MainMenu::GREETING, MainMenu::PROMPT, MainMenu::NOT_READY,
        MainMenu::STATE_EXPIRED, MainMenu::UNKNOWN, MainMenu::CALLBACK_EXPIRED,
        MainMenu::HELP, MainMenu::RESTRICTED,
    ];

    foreach ($customerFacing as $message) {
        expect(strtolower($message))->not->toContain('phase')
            ->and(strtolower($message))->not->toContain('todo')
            ->and(strtolower($message))->not->toContain('not implemented');
    }
});

it('knows which entries actually do something yet', function (): void {
    expect(TelegramAction::Start->isImplemented())->toBeTrue()
        ->and(TelegramAction::MenuHelp->isImplemented())->toBeTrue()
        ->and(TelegramAction::MenuProfile->isImplemented())->toBeTrue()
        // The commerce entries arrive with the sales phase.
        ->and(TelegramAction::MenuBuyServer->isImplemented())->toBeFalse()
        ->and(TelegramAction::MenuWallet->isImplemented())->toBeFalse();
});

it('shows a customer their own identity and nothing else', function (): void {
    $profile = MainMenu::profile(5_500_123_456, UserStatus::Active);

    expect($profile)->toContain('5500123456')
        ->and($profile)->toContain('فعال')
        // Balances and servers belong to the entries that own them.
        ->and($profile)->not->toContain('تومان');
});

it('carries no authority in its buttons', function (): void {
    $keyboard = json_encode(MainMenu::keyboard(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);

    // A reply keyboard carries labels only: no callback data to tamper with,
    // no identifier, no price, no ownership claim.
    expect($keyboard)->not->toContain('callback_data')
        ->and($keyboard)->not->toContain('user_id')
        ->and($keyboard)->not->toContain('price');
});
