<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
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

    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect' => env('GOOGLE_REDIRECT_URI'),
        'guzzle' => [
            'connect_timeout' => (float) env('GOOGLE_CONNECT_TIMEOUT', 5),
            'timeout' => (float) env('GOOGLE_HTTP_TIMEOUT', 10),
            'force_ip_resolve' => env('GOOGLE_IP_RESOLVE', 'v4'),
        ],
    ],

    'groq' => [
        'api_key' => env('GROQ_API_KEY'),
        'signature_model' => env('GROQ_SIGNATURE_MODEL', 'qwen/qwen3.8-27b'),
        'connect_timeout' => min(10, max(1, (int) env('GROQ_CONNECT_TIMEOUT', 3))),
        'timeout' => min(60, max(1, (int) env('GROQ_TIMEOUT', 15))),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
