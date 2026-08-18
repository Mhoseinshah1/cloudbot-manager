<?php

use App\Jobs\ProcessTelegramUpdateJob;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    config()->set('telegram.webhook_secret', 'hardening-secret');
    Cache::flush();
});

it('fails closed when the Telegram webhook secret is not configured', function () {
    config()->set('telegram.webhook_secret', '');
    Queue::fake();

    $response = $this->postJson('/telegram/webhook', [
        'update_id' => 88001,
        'message' => [
            'text' => '/start',
            'chat' => ['id' => 1],
            'from' => ['id' => 2],
        ],
    ]);

    $response->assertStatus(500);
    Queue::assertNothingPushed();
});

it('queues a retryable Telegram update job exactly once per update id', function () {
    Queue::fake();

    $payload = [
        'update_id' => 88002,
        'message' => [
            'text' => '/start',
            'chat' => ['id' => 10],
            'from' => ['id' => 20, 'first_name' => 'Test'],
        ],
    ];

    $headers = ['X-Telegram-Bot-Api-Secret-Token' => 'hardening-secret'];

    $this->postJson('/telegram/webhook', $payload, $headers)->assertOk();
    $this->postJson('/telegram/webhook', $payload, $headers)->assertOk()->assertJson(['duplicate' => true]);

    Queue::assertPushed(ProcessTelegramUpdateJob::class, 1);
    Queue::assertPushed(ProcessTelegramUpdateJob::class, function (ProcessTelegramUpdateJob $job) use ($payload) {
        return $job->tries === 3
            && $job->backoff === 5
            && $job->update['update_id'] === $payload['update_id'];
    });
});

it('rejects an invalid configured webhook secret before queueing work', function () {
    Queue::fake();

    $this->postJson('/telegram/webhook', [
        'update_id' => 88003,
    ], ['X-Telegram-Bot-Api-Secret-Token' => 'wrong'])
        ->assertStatus(403);

    Queue::assertNothingPushed();
});
