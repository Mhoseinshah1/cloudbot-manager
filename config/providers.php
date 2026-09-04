<?php

use App\Cloud\Fake\FakeProvider;

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
    | Hetzner is not listed: it has no implementation yet, and an entry
    | pointing at a class that cannot provision would be a claim the system
    | cannot honour.
    |
    */

    'implementations' => [
        'fake' => FakeProvider::class,
    ],

];
