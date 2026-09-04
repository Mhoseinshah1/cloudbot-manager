<?php

declare(strict_types=1);

use App\Telegram\Callbacks\CallbackGrammar;
use App\Telegram\Enums\TelegramAction;

/**
 * What the bot will and will not read out of a pressed button.
 *
 * Callback data is not signed and not private: a customer can send any string
 * they like in it. So these are the tests that matter — not that a well-formed
 * button parses, but that a malformed, oversized, forged or cleverly shaped one
 * becomes nothing at all.
 */
it('reads the shapes it draws', function (string $data, TelegramAction $expected): void {
    expect(CallbackGrammar::parse($data)['action'])->toBe($expected);
})->with([
    'a product' => ['buy:p:a1b2c3d4:42', TelegramAction::BuyProduct],
    'a location' => ['buy:l:a1b2c3d4:42', TelegramAction::BuyLocation],
    'an image' => ['buy:i:a1b2c3d4:42', TelegramAction::BuyImage],
    'the default image' => ['buy:i:a1b2c3d4:d', TelegramAction::BuyImage],
    'a page of products' => ['buy:pg:a1b2c3d4:3', TelegramAction::BuyPage],
    'accepting terms' => ['buy:aup:a1b2c3d4', TelegramAction::BuyAcceptTerms],
    'paying' => ['buy:ok:a1b2c3d4', TelegramAction::BuyConfirm],
    'giving up' => ['buy:x:a1b2c3d4', TelegramAction::BuyCancel],
    'a server' => ['srv:v:7', TelegramAction::ServerView],
    'powering on' => ['srv:on:7', TelegramAction::ServerPowerOn],
    'powering off' => ['srv:off:7', TelegramAction::ServerPowerOff],
    'rebooting' => ['srv:rb:7', TelegramAction::ServerReboot],
    'revealing a password' => ['srv:pw:7', TelegramAction::ServerRevealPassword],
    'asking to delete' => ['srv:del:7', TelegramAction::ServerDelete],
    'confirming a delete' => ['srv:delok:a1b2c3d4', TelegramAction::ServerDeleteConfirm],
    'a wallet page' => ['wal:pg:2', TelegramAction::WalletPage],
    'an invoice' => ['inv:v:9', TelegramAction::InvoiceView],
    'the menu' => ['menu:main', TelegramAction::MainMenu],
]);

it('refuses anything it did not draw', function (string $data): void {
    expect(CallbackGrammar::parse($data)['action'])->toBe(TelegramAction::Unknown);
})->with([
    'an unknown verb' => ['srv:destroy:7'],
    'an unknown namespace' => ['admin:v:7'],
    'too few segments' => ['srv:v'],
    'too many segments' => ['buy:p:a1b2c3d4:1:2'],
    'a non-numeric id' => ['srv:v:seven'],
    'a negative id' => ['srv:v:-7'],
    'a zero id' => ['srv:v:0'],
    'a leading zero' => ['srv:v:007'],
    'an id with a space' => ['srv:v: 7'],
    'an sql-shaped id' => ['srv:v:1 OR 1=1'],
    'a token that is not hex' => ['buy:ok:zzzzzzzz'],
    'a token that is too short' => ['buy:ok:ab'],
    'a token that is too long' => ['buy:ok:'.str_repeat('a', 40)],
    'a page of zero' => ['wal:pg:0'],
    'an absurd page' => ['wal:pg:9999999'],
    'the default marker outside the image step' => ['buy:p:a1b2c3d4:d'],
    'empty' => [''],
    'only separators' => ['::'],
    'a forged menu entry' => ['menu:admin'],
]);

it('refuses callback data longer than telegram would ever send', function (): void {
    // Bounded before it is split, so an oversized payload is never parsed at
    // all. Telegram's own limit is 64 bytes; anything longer did not come from
    // a keyboard this system drew.
    $oversized = 'srv:v:'.str_repeat('1', CallbackGrammar::MAX_LENGTH);

    expect(strlen($oversized))->toBeGreaterThan(CallbackGrammar::MAX_LENGTH)
        ->and(CallbackGrammar::parse($oversized)['action'])->toBe(TelegramAction::Unknown);
});

it('keeps every button it draws inside telegram\'s limit', function (): void {
    // The largest id PostgreSQL can produce for a bigserial, with the longest
    // token this system generates. A button that fitted in development and
    // overflowed in production would simply stop working for the customers who
    // had been here longest.
    $id = 9_223_372_036_854_775_807;
    $token = str_repeat('a', 16);

    $drawn = [
        CallbackGrammar::buyProduct($token, $id),
        CallbackGrammar::buyLocation($token, $id),
        CallbackGrammar::buyImage($token, $id),
        CallbackGrammar::buyDefaultImage($token),
        CallbackGrammar::buyPage($token, 10_000),
        CallbackGrammar::buyAcceptTerms($token),
        CallbackGrammar::buyConfirm($token),
        CallbackGrammar::buyCancel($token),
        CallbackGrammar::serverView($id),
        CallbackGrammar::serverPowerOn($id),
        CallbackGrammar::serverPowerOff($id),
        CallbackGrammar::serverReboot($id),
        CallbackGrammar::serverRevealPassword($id),
        CallbackGrammar::serverDelete($id),
        CallbackGrammar::serverDeleteConfirm($token),
        CallbackGrammar::serverPage(10_000),
        CallbackGrammar::walletPage(10_000),
        CallbackGrammar::invoicePage(10_000),
        CallbackGrammar::invoiceView($id),
    ];

    foreach ($drawn as $data) {
        expect(strlen($data))->toBeLessThanOrEqual(CallbackGrammar::MAX_LENGTH, $data);
    }
});

it('round-trips every shape it draws', function (): void {
    // The parser and the builder are two halves of one grammar. Halves that
    // live apart drift, and the direction they drift in is buttons that no
    // longer parse — so every shape the builder produces is parsed back here.
    $token = 'a1b2c3d4';

    $cases = [
        CallbackGrammar::buyProduct($token, 42) => TelegramAction::BuyProduct,
        CallbackGrammar::buyLocation($token, 42) => TelegramAction::BuyLocation,
        CallbackGrammar::buyImage($token, 42) => TelegramAction::BuyImage,
        CallbackGrammar::buyDefaultImage($token) => TelegramAction::BuyImage,
        CallbackGrammar::buyPage($token, 2) => TelegramAction::BuyPage,
        CallbackGrammar::buyAcceptTerms($token) => TelegramAction::BuyAcceptTerms,
        CallbackGrammar::buyConfirm($token) => TelegramAction::BuyConfirm,
        CallbackGrammar::buyCancel($token) => TelegramAction::BuyCancel,
        CallbackGrammar::serverView(42) => TelegramAction::ServerView,
        CallbackGrammar::serverDeleteConfirm($token) => TelegramAction::ServerDeleteConfirm,
        CallbackGrammar::walletPage(2) => TelegramAction::WalletPage,
        CallbackGrammar::invoiceView(42) => TelegramAction::InvoiceView,
    ];

    foreach ($cases as $data => $action) {
        expect(CallbackGrammar::parse((string) $data)['action'])->toBe($action, (string) $data);
    }
});

it('carries the hints it parsed, and only those', function (): void {
    $parsed = CallbackGrammar::parse('buy:p:a1b2c3d4:42');

    expect($parsed['parameters']->id)->toBe(42)
        ->and($parsed['parameters']->flowToken)->toBe('a1b2c3d4')
        ->and($parsed['parameters']->page)->toBeNull()
        ->and($parsed['parameters']->wantsDefault)->toBeFalse();

    $default = CallbackGrammar::parse('buy:i:a1b2c3d4:d');

    // The default is a choice of its own, not the absence of one.
    expect($default['parameters']->wantsDefault)->toBeTrue()
        ->and($default['parameters']->id)->toBeNull();
});

it('never turns a delete confirmation into a server id', function (): void {
    // The confirmation carries a token and nothing else, so a stale keyboard
    // cannot aim a week-old confirmation at a server chosen since. The id comes
    // from the delete intent this system wrote.
    $parsed = CallbackGrammar::parse(CallbackGrammar::serverDeleteConfirm('a1b2c3d4'));

    expect($parsed['action'])->toBe(TelegramAction::ServerDeleteConfirm)
        ->and($parsed['parameters']->id)->toBeNull()
        ->and($parsed['parameters']->flowToken)->toBe('a1b2c3d4');
});
