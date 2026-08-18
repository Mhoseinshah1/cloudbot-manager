<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Hourly Billing Configuration
    |--------------------------------------------------------------------------
    |
    | Controls the behavior of hourly and hourly_capped VPS billing.
    | All money values are integer Toman (IRR / 10).
    |
    */

    'hourly' => [

        /*
        |--------------------------------------------------------------------------
        | Minimum Prepaid Hours
        |--------------------------------------------------------------------------
        |
        | The minimum number of hours a customer wallet must be funded to
        | cover before an hourly/hourly_capped VPS can be provisioned.
        | The required balance = minimum_prepaid_hours × hourly customer rate.
        |
        */
        'minimum_prepaid_hours' => (int) env('HOURLY_MINIMUM_PREPAID_HOURS', 24),

        /*
        |--------------------------------------------------------------------------
        | Grace Period Hours
        |--------------------------------------------------------------------------
        |
        | After the wallet is depleted and the server enters the "grace"
        | billing state, how many hours before a lifecycle action is
        | performed (notify_only, power_off, or terminate_after_grace).
        |
        */
        'grace_hours' => (int) env('HOURLY_GRACE_HOURS', 48),

        /*
        |--------------------------------------------------------------------------
        | Lifecycle Action
        |--------------------------------------------------------------------------
        |
        | The action performed when the grace period expires without the
        | balance being replenished. Must be one of:
        |
        |   "notify_only"       — record the state, send no provider action
        |   "power_off"         — power the server off at the provider
        |   "terminate_after_grace" — power off, then delete the server
        |
        | Note: powering off does NOT stop upstream provider billing or
        | customer hourly billing. Only permanent deletion stops billing.
        |
        */
        'lifecycle_action' => env('HOURLY_LIFECYCLE_ACTION', 'notify_only'),

        /*
        |--------------------------------------------------------------------------
        | Low Balance Warning Hours
        |--------------------------------------------------------------------------
        |
        | An array of hour thresholds at which a low-balance warning is
        | created. When the estimated remaining balance falls below
        | threshold × hourly_rate, a deduplicated warning record is
        | emitted. Future Telegram handlers consume these.
        |
        */
        'low_balance_warning_hours' => array_map(
            'intval',
            array_filter(explode(',', env('HOURLY_LOW_BALANCE_WARNING_HOURS', '24,12,6')))
        ),

    ],

    /*
    |--------------------------------------------------------------------------
    | Rounding Policy
    |--------------------------------------------------------------------------
    |
    | How partially-consumed hours are rounded when billing or terminating.
    |
    |   "ceil"  — a started hour is billed in full (default, most common)
    |   "floor" — only fully-consumed hours are billed
    |   "round" — nearest whole hour (30m+ rounds up)
    |
    | Stored in the settings table as "billing.hourly_rounding" and read
    | at runtime via Setting::get(); the value here is the fallback.
    |
    */
    'hourly_rounding' => env('HOURLY_ROUNDING', 'ceil'),

];
