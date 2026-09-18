<?php

use App\Cloud\Fake\FakeProvider;
use App\Cloud\Hetzner\HetznerProvider;

return [

    /*
    |--------------------------------------------------------------------------
    | Provider Implementation Registry
    |--------------------------------------------------------------------------
    |
    | The only place a provider implementation class may be named. A `code` in
    | the providers table selects an entry here; the database never holds a
    | class name, because a write to that table would otherwise decide which
    | code this application instantiates.
    |
    | ProviderManager resolves from this list and refuses anything else, so
    | adding a provider is a deliberate, reviewed change to this file.
    |
    | Both entries are real implementations. The simulator stays registered
    | because the conformance suite runs the same contract against both, and a
    | provider that only the tests can reach is a provider nothing verifies.
    |
    */

    'implementations' => [
        'fake' => FakeProvider::class,
        'hetzner' => HetznerProvider::class,
    ],

];
