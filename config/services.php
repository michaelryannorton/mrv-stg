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
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    // The status dashboard's health endpoint (App\Http\Controllers\StatusHealthController, route /_status/health).
    // Every value is set per copy in that copy's .env. Without a token hash the endpoint cannot be used.
    'status_health' => [
        'token_hash' => env('STATUS_HEALTH_TOKEN_HASH'),
        'token_hash_next' => env('STATUS_HEALTH_TOKEN_HASH_NEXT'),
        'property' => env('STATUS_HEALTH_PROPERTY'),
        'environment' => env('STATUS_HEALTH_ENVIRONMENT'),
        'php_version' => env('STATUS_HEALTH_PHP_VERSION'),
        'database' => env('STATUS_HEALTH_DATABASE', true),
        'connections' => env('STATUS_HEALTH_DB_CONNECTIONS', ''),
        'scheduler' => env('STATUS_HEALTH_SCHEDULER', false),
        'queue' => env('STATUS_HEALTH_QUEUE', false),
        'disabled_groups' => env('STATUS_HEALTH_DISABLED_GROUPS', ''),
        'status_dir' => env('STATUS_HEALTH_STATUS_DIR'),
        'heartbeats' => [
            'ingest_ics' => env('STATUS_HEALTH_HEARTBEAT_INGEST_ICS'),
        ],
    ],

];
