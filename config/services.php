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

    'jolpica' => [
        'base_url' => env('JOLPICA_BASE_URL', 'https://api.jolpi.ca/ergast/f1'),
        // Jolpica е rate-limited (без ключ: ~4 req/s, 500/час burst).
        'timeout' => (int) env('JOLPICA_TIMEOUT', 20),
        'retry_times' => (int) env('JOLPICA_RETRY_TIMES', 3),
        'retry_sleep_ms' => (int) env('JOLPICA_RETRY_SLEEP_MS', 500),
    ],

];
