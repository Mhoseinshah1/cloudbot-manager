<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Customer-Facing Timezone
    |--------------------------------------------------------------------------
    |
    | Every timestamp is stored and calculated in UTC (see config/app.php).
    | This value is presentation only: it is the timezone customer-facing
    | output is rendered in once such output exists.
    |
    */

    'customer_timezone' => 'Asia/Tehran',

    /*
    |--------------------------------------------------------------------------
    | Health
    |--------------------------------------------------------------------------
    |
    | Health checks must stay cheap: they run on every container probe. The
    | timeout caps how long a single dependency probe may block.
    |
    */

    /*
    |--------------------------------------------------------------------------
    | Administrators
    |--------------------------------------------------------------------------
    |
    | Privileged accounts require a second factor. This switch exists so the
    | automated tests can exercise the unenrolled path; it is ignored in
    | production, where the requirement always applies.
    |
    */

    'admin' => [
        'require_two_factor' => (bool) env('ADMIN_REQUIRE_TWO_FACTOR', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Customer Defaults
    |--------------------------------------------------------------------------
    |
    | Applied to accounts created without an explicit preference.
    |
    */

    'defaults' => [
        'locale' => env('CLOUDBOT_DEFAULT_LOCALE', 'fa'),
        'timezone' => 'Asia/Tehran',
    ],

    'health' => [
        'timeout_seconds' => (int) env('HEALTH_TIMEOUT_SECONDS', 2),
    ],

];
