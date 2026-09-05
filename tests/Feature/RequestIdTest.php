<?php

declare(strict_types=1);

use App\Http\Middleware\AssignRequestId;

it('assigns a request id when none is supplied', function (): void {
    $response = $this->getJson('/health')->assertOk();

    expect($response->headers->get(AssignRequestId::HEADER))->not->toBeEmpty();
});

it('keeps a well formed inbound request id', function (): void {
    $this->getJson('/health', [AssignRequestId::HEADER => 'abc-123_DEF.4'])
        ->assertHeader(AssignRequestId::HEADER, 'abc-123_DEF.4');
});

it('replaces a malformed inbound request id', function (): void {
    // A caller must not be able to inject arbitrary text into our log lines.
    $response = $this->getJson('/health', [AssignRequestId::HEADER => "bad value\ninjected"]);

    expect($response->headers->get(AssignRequestId::HEADER))->not->toBe("bad value\ninjected");
});

it('replaces an over-long inbound request id', function (): void {
    $response = $this->getJson('/health', [AssignRequestId::HEADER => str_repeat('a', 200)]);

    expect(strlen((string) $response->headers->get(AssignRequestId::HEADER)))->toBeLessThanOrEqual(64);
});
