<?php

declare(strict_types=1);

use App\Support\Queues;

it('defines the queues the workers drain', function (): void {
    expect(Queues::names())->toBe(['telegram', 'provisioning', 'notifications', 'default']);
});

it('keeps interactive work separate from provisioning', function (): void {
    // A slow provider call must never block bot replies, so these can never
    // be the same queue.
    expect(Queues::Telegram->value)->not->toBe(Queues::Provisioning->value);
});

it('defines no release 1.1 billing queue yet', function (): void {
    expect(Queues::tryFrom('billing'))->toBeNull();
});
