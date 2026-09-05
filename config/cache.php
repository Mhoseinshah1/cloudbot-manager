<?php

use Illuminate\Support\Str;

return [

    /*
    |--------------------------------------------------------------------------
    | Default Cache Store
    |--------------------------------------------------------------------------
    */

    'default' => env('CACHE_STORE', 'redis'),

    /*
    |--------------------------------------------------------------------------
    | Cache Stores
    |--------------------------------------------------------------------------
    |
    | The `redis` store is flushable: `cache:clear` runs FLUSHDB against its
    | connection. It therefore must point at the cache database only.
    |
    | The `locks` store is a separate Redis database so that distributed locks
    | (used from Phase 7 onwards to coordinate provisioning) survive a cache
    | flush. Locks coordinate work; they are never the source of truth.
    |
    */

    'stores' => [

        'array' => [
            'driver' => 'array',
            'serialize' => false,
        ],

        'redis' => [
            'driver' => 'redis',
            'connection' => 'cache',
            'lock_connection' => 'locks',
        ],

        'locks' => [
            'driver' => 'redis',
            'connection' => 'locks',
            'lock_connection' => 'locks',
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Cache Key Prefix
    |--------------------------------------------------------------------------
    */

    'prefix' => env('CACHE_PREFIX', Str::slug(env('APP_NAME', 'cloudbot'), '_').'_cache_'),

];
