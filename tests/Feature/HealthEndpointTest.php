<?php

declare(strict_types=1);

use Illuminate\Testing\Fluent\AssertableJson;

it('reports ok when dependencies are reachable', function (): void {
    $this->getJson('/health')
        ->assertOk()
        ->assertJson([
            'status' => 'ok',
            'services' => ['database' => 'up', 'redis' => 'up'],
        ]);
});

it('reports 503 when the database is unreachable', function (): void {
    // Point the connection at a closed port rather than mocking, so the real
    // failure path runs.
    config()->set('database.connections.pgsql.host', '127.0.0.1');
    config()->set('database.connections.pgsql.port', 1);
    DB::purge('pgsql');

    $this->getJson('/health')
        ->assertStatus(503)
        ->assertJson([
            'status' => 'degraded',
            'services' => ['database' => 'down'],
        ]);
});

it('reports 503 when redis is unreachable', function (): void {
    config()->set('database.redis.state.host', '127.0.0.1');
    config()->set('database.redis.state.port', 1);

    $this->getJson('/health')
        ->assertStatus(503)
        ->assertJson([
            'status' => 'degraded',
            'services' => ['redis' => 'down'],
        ]);
});

it('exposes no internal detail', function (): void {
    $response = $this->getJson('/health')->assertOk();

    // The endpoint is unauthenticated. It must not leak anything an attacker
    // could use: hostnames, credentials, versions or exception text.
    $response->assertJson(fn (AssertableJson $json) => $json
        ->hasAll(['status', 'services'])
        ->has('services', fn (AssertableJson $services) => $services
            ->hasAll(['database', 'redis'])
            ->etc()
        )
    );

    $body = $response->getContent();

    foreach (['password', 'host', 'version', 'exception', 'dsn', 'pgsql', '127.0.0.1', 'trace'] as $leak) {
        expect(strtolower((string) $body))->not->toContain($leak);
    }
});

it('is not cached by intermediaries', function (): void {
    $this->getJson('/health')->assertHeader('Cache-Control', 'no-store, private');
});

it('does not start a session', function (): void {
    // Probes run constantly; a session per probe would write a row every time.
    $response = $this->getJson('/health')->assertOk();

    expect($response->headers->getCookies())->toBeEmpty();
});
