<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Telegram Bot Configuration
    |--------------------------------------------------------------------------
    */

    'bot_token' => env('TELEGRAM_BOT_TOKEN', ''),

    'bot_username' => env('TELEGRAM_BOT_USERNAME', ''),

    'webhook_secret' => env('TELEGRAM_WEBHOOK_SECRET', ''),

    'api_base_url' => env('TELEGRAM_API_BASE_URL', 'https://api.telegram.org'),

    'webhook_url' => env('TELEGRAM_WEBHOOK_URL', ''),

    /*
    |--------------------------------------------------------------------------
    | Conversation State TTL (seconds)
    |--------------------------------------------------------------------------
    |
    | How long Telegram conversation state persists in Redis before expiry.
    |
    */
    'state_ttl' => (int) env('TELEGRAM_STATE_TTL', 3600),

    /*
    |--------------------------------------------------------------------------
    | Pagination
    |--------------------------------------------------------------------------
    */
    'servers_per_page' => 5,

];
