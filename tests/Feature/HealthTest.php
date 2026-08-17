<?php

it('reports healthy when the database is reachable', function () {
    $this->get('/health')
        ->assertOk()
        ->assertJsonPath('status', 'ok')
        ->assertJsonPath('services.database', 'up')
        ->assertJsonStructure(['status', 'services', 'time']);
});

it('never exposes configuration or secrets in the health response', function () {
    $response = $this->get('/health');

    expect($response->getContent())->not->toContain('APP_KEY');
    expect($response->getContent())->not->toContain('DB_PASSWORD');
    expect($response->getContent())->not->toContain('HETZNER');
    expect($response->getContent())->not->toContain('ZARINPAL');
    expect($response->getContent())->not->toContain('TELEGRAM');
});
