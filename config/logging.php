<?php

use App\Logging\RedactSecrets;
use Monolog\Formatter\JsonFormatter;
use Monolog\Handler\NullHandler;
use Monolog\Handler\StreamHandler;
use Monolog\Processor\PsrLogMessageProcessor;

return [

    /*
    |--------------------------------------------------------------------------
    | Default Log Channel
    |--------------------------------------------------------------------------
    |
    | Containers log to stderr and Docker owns rotation (see compose.yaml).
    | Writing log files inside a container would grow unbounded and be lost on
    | recreate, so no file channel is configured for production.
    |
    */

    'default' => env('LOG_CHANNEL', 'stderr'),

    /*
    |--------------------------------------------------------------------------
    | Deprecations Channel
    |--------------------------------------------------------------------------
    */

    'deprecations' => [
        'channel' => env('LOG_DEPRECATIONS_CHANNEL', 'null'),
        'trace' => false,
    ],

    /*
    |--------------------------------------------------------------------------
    | Log Channels
    |--------------------------------------------------------------------------
    |
    | Every channel taps RedactSecrets. Credentials must not reach logs, and
    | relying on each call site to remember that would eventually fail.
    |
    */

    'channels' => [

        'stack' => [
            'driver' => 'stack',
            'channels' => explode(',', (string) env('LOG_STACK', 'stderr')),
            'ignore_exceptions' => false,
        ],

        'stderr' => [
            'driver' => 'monolog',
            'level' => env('LOG_LEVEL', 'info'),
            'handler' => StreamHandler::class,
            'handler_with' => [
                'stream' => 'php://stderr',
            ],
            'formatter' => JsonFormatter::class,
            'processors' => [PsrLogMessageProcessor::class],
            'tap' => [RedactSecrets::class],
        ],

        'single' => [
            'driver' => 'single',
            'path' => storage_path('logs/cloudbot.log'),
            'level' => env('LOG_LEVEL', 'debug'),
            'tap' => [RedactSecrets::class],
            'replace_placeholders' => true,
        ],

        'null' => [
            'driver' => 'monolog',
            'handler' => NullHandler::class,
        ],

        'emergency' => [
            'path' => storage_path('logs/cloudbot.log'),
        ],

    ],

];
