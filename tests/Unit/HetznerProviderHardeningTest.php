<?php

use App\Providers\Cloud\HetznerProvider;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Fixtures\HetznerApiFixtures as F;

it('sends include_deprecated to Hetzner as a boolean query parameter', function () {
    Http::fake([
        'api.hetzner.test/v1/images*' => Http::response(F::imagesResponse()),
    ]);

    $provider = new HetznerProvider(
        credentials: ['token' => F::TOKEN],
        options: [
            'base_url' => F::BASE_URL,
            'retry_attempts' => 1,
            'retry_delay_ms' => 1,
        ],
    );

    $provider->getImages();

    Http::assertSent(function (Request $request): bool {
        return str_contains($request->url(), '/images')
            && $request['type'] === 'system'
            && $request['include_deprecated'] === true;
    });
});
