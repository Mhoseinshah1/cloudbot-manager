<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'hetzner' => [
        'token' => env('HETZNER_API_TOKEN'),
        'base_url' => env('HETZNER_API_BASE_URL', 'https://api.hetzner.cloud/v1'),
        'timeout' => (int) env('HETZNER_API_TIMEOUT', 30),
        'connect_timeout' => (int) env('HETZNER_API_CONNECT_TIMEOUT', 5),
        'action_timeout' => (int) env('HETZNER_ACTION_TIMEOUT', 300),
        'action_polling_interval_ms' => (int) env('HETZNER_ACTION_POLLING_INTERVAL_MS', 2000),
    ],

];
