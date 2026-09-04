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

    'health' => [
        'timeout_seconds' => (int) env('HEALTH_TIMEOUT_SECONDS', 2),
    ],

];
