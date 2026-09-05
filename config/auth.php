<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Authentication Defaults
    |--------------------------------------------------------------------------
    |
    | There is one guard. Release 1.0 has no customer web login: customers use
    | the Telegram bot, and this guard exists for the admin panel.
    |
    */

    'defaults' => [
        'guard' => 'web',
        'passwords' => 'users',
    ],

    'guards' => [
        'web' => [
            'driver' => 'session',
            'provider' => 'users',
        ],
    ],

    'providers' => [
        'users' => [
            'driver' => 'eloquent',
            'model' => App\Models\User::class,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Resetting Passwords
    |--------------------------------------------------------------------------
    |
    | No reset broker is configured: there is no self-service password reset
    | flow, and no table for one. An administrator who loses access is handled
    | by an operator with console access.
    |
    */

    'passwords' => [],

    'password_timeout' => 10800,

];
