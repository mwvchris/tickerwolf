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

    'polygon' => [
        'base' => env('POLYGON_API_BASE', 'https://api.polygon.io'),
        'key'  => env('POLYGON_API_KEY'),
    ],

    'openai' => [
        'key' => env('OPENAI_API_KEY'),
        'endpoint' => env('OPENAI_API_BASE', 'https://api.openai.com/v1/responses'),
        'model' => env('OPENAI_MODEL', 'gpt-4.1'),
    ],

    'gemini' => [
        'key' => env('GEMINI_API_KEY'),
        'endpoint' => env('GEMINI_API_ENDPOINT', 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5pro:generateContent'),
        'model' => env('GEMINI_MODEL', 'gemini-2.5-pro'),
    ],

    'grok' => [
        'key' => env('GROK_API_KEY'),
        'endpoint' => env('GROK_API_ENDPOINT', 'https://api.x.ai/v1/chat/completions'),
        'model' => env('GROK_MODEL', 'grok-4-fast-reasoning'),
    ],

];
