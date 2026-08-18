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
    | Wallet top-up safety
    |--------------------------------------------------------------------------
    |
    | Free/automatic top-up is disabled by default. When explicitly enabled
    | for development/testing, the flow still uses Order → Invoice → Payment
    | and only auto-confirms the manual payment; it never mutates the wallet
    | directly from the Telegram layer.
    |
    */
    'allow_free_topup' => (bool) env('TELEGRAM_ALLOW_FREE_TOPUP', false),
    'topup_gateway' => env('TELEGRAM_TOPUP_GATEWAY', 'manual'),
    'topup_min_toman' => (int) env('TELEGRAM_TOPUP_MIN_TOMAN', 10000),
    'topup_max_toman' => (int) env('TELEGRAM_TOPUP_MAX_TOMAN', 50000000),

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
