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

    /*
    |--------------------------------------------------------------------------
    | Provisioning
    |--------------------------------------------------------------------------
    |
    | Infrastructure, not business policy. How long a provider call may take
    | and how long a coordination lock is held are properties of the deployment
    | — a slower network needs a longer timeout — so they belong in config,
    | where an operator can tune them per environment.
    |
    | The controls that decide whether provisioning may happen at all, and how
    | long an order may sit before a sweep looks at it, are DB settings instead:
    | those are business decisions, they change during an incident, and they
    | must not require a redeploy.
    |
    | The lock TTL must be at least twice the provider timeout. The lock exists
    | to stop two workers calling one provider at once, and a lock that can
    | expire while a call is still in flight does not do that. Nothing here is
    | the duplicate-prevention mechanism — that is the durable token, the
    | provider's own idempotency and the unique constraints — but a lock that
    | lies about what it covers is worse than none.
    |
    */

    'provisioning' => [
        'provider_timeout_seconds' => (int) env('PROVIDER_OPERATION_TIMEOUT_SECONDS', 120),
        'lock_ttl_seconds' => (int) env('PROVISIONING_LOCK_TTL_SECONDS', 300),

        // The specification's retry policy. Three attempts, backing off, and
        // never a create retry that has not first reconciled the token.
        'max_attempts' => 3,
        'backoff_seconds' => [30, 120, 600],

        // How many stuck orders one sweep may claim. Bounded so the sweeper
        // cannot pull an unbounded table into memory, and so a backlog is
        // worked through in steady batches rather than one enormous run.
        'reconcile_batch' => 100,
    ],

    /*
    |--------------------------------------------------------------------------
    | Server Actions
    |--------------------------------------------------------------------------
    |
    | Power, reboot and delete. The attempt cap is the reason this section
    | exists: a destructive request that retried forever would keep asking a
    | provider to delete a machine it may already have deleted, and "keep
    | trying" is not a safe default for anything irreversible. An action that
    | runs out of attempts becomes somebody's decision rather than a loop.
    |
    */

    'server_actions' => [
        'max_attempts' => 3,
        'lock_ttl_seconds' => (int) env('SERVER_ACTION_LOCK_TTL_SECONDS', 300),

        // How long an action may sit unsettled before the reconciler asks the
        // provider what happened to it.
        'reconcile_after_seconds' => 60,
        'reconcile_batch' => 100,
    ],

    /*
    |--------------------------------------------------------------------------
    | Root Password Reveal
    |--------------------------------------------------------------------------
    |
    | How long a revealed password stays on screen before the bot tries to
    | delete the message. Best effort only, and the security of the system
    | never depends on it succeeding: Telegram may refuse, the customer may
    | have forwarded it, and the message may already be on somebody's laptop.
    | It is a courtesy that shortens the window, not a control.
    |
    */

    'server_credentials' => [
        'reveal_visible_seconds' => (int) env('ROOT_PASSWORD_VISIBLE_SECONDS', 60),
    ],

    /*
    |--------------------------------------------------------------------------
    | Outbox Delivery
    |--------------------------------------------------------------------------
    |
    | How many undelivered intents one sweep may pick up, and how many times
    | one may be attempted before it stops being retried automatically. Both
    | bounded: an unbounded query would pull a backlog into memory, and an
    | unbounded retry would hammer Telegram with a message it will never
    | accept.
    |
    */

    'outbox' => [
        'dispatch_batch' => 100,
        'max_attempts' => 5,
    ],

];
