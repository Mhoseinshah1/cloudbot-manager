<?php

declare(strict_types=1);

use App\Cloud\Fake\FakeCatalog;
use App\Cloud\Fake\FakeProvider;
use Tests\Support\Cloud\ProviderConformance;

/**
 * FakeProvider must satisfy the same contract as a real provider.
 *
 * The suite itself lives in Tests\Support\Cloud so Phase 10 can run it against
 * Hetzner unchanged.
 */
ProviderConformance::describe(
    'fake',
    // Resolved fresh each time, so the suite exercises state crossing
    // independent instances rather than one long-lived object.
    fn () => app(FakeProvider::class),
    [
        'plan' => FakeCatalog::PLAN_SMALL,
        'location' => FakeCatalog::LOCATION_PRIMARY,
        'image' => FakeCatalog::IMAGE_UBUNTU,
    ],
);
