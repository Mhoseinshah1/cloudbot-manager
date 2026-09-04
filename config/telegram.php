<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Bot Credentials
    |--------------------------------------------------------------------------
    |
    | Neither has a default, and neither may ever get one. A bot token is the
    | whole authority to act as this business in Telegram, and a webhook secret
    | is the only thing separating a real Telegram delivery from anyone on the
    | internet posting to the endpoint. A fallback value here would be a
    | published credential.
    |
    | Both are absent from every log and every exception. The scrubber already
    | recognises the `bot<digits>:<token>` shape that appears in API URLs.
    |
    */

    'bot_token' => env('TELEGRAM_BOT_TOKEN'),

    'webhook_secret' => env('TELEGRAM_WEBHOOK_SECRET'),

    /*
    |--------------------------------------------------------------------------
    | API
    |--------------------------------------------------------------------------
    |
    | The base URL is configurable so tests and a local proxy can point
    | elsewhere; the default is Telegram's own. The timeout is infrastructure:
    | this runs on the interactive worker, where a customer is waiting.
    |
    */

    'api_base_url' => env('TELEGRAM_API_BASE_URL', 'https://api.telegram.org'),

    'timeout_seconds' => (int) env('TELEGRAM_TIMEOUT_SECONDS', 10),

    /*
    |--------------------------------------------------------------------------
    | Webhook
    |--------------------------------------------------------------------------
    |
    | The public URL Telegram delivers to, used by the webhook management
    | commands. Deployment owns how this host comes to exist; this only records
    | where to point the bot once it does.
    |
    */

    'webhook_url' => env('TELEGRAM_WEBHOOK_URL'),

    /*
    |--------------------------------------------------------------------------
    | Rate Limiting
    |--------------------------------------------------------------------------
    |
    | Telegram answers a 429 with a `retry_after`. It is honoured, but not
    | blindly: a malformed or absurd value must not become either a hot retry
    | loop or a job parked for a week, so it is clamped into a sane band.
    |
    */

    'retry_after' => [
        'minimum_seconds' => 1,
        'maximum_seconds' => 300,
        'fallback_seconds' => 5,
    ],

    /*
    |--------------------------------------------------------------------------
    | Conversation State
    |--------------------------------------------------------------------------
    |
    | Ephemeral, and deliberately so. Conversation state lives in the dedicated
    | Redis `state` database — not the cache, which `cache:clear` flushes
    | wholesale, and not PostgreSQL, which is for things that must outlive a
    | conversation.
    |
    | The TTL is infrastructure rather than business policy: it decides how long
    | a half-finished conversation is remembered, not what anything costs.
    |
    */

    'state' => [
        'connection' => 'state',
        'ttl_seconds' => (int) env('TELEGRAM_STATE_TTL_SECONDS', 1800),
    ],

];
