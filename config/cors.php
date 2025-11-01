<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | This file controls how your application handles cross-origin requests.
    | It’s designed for Sanctum SPA setups, allowing credentialed requests
    | (cookies, CSRF) from your frontend origin.
    |
    */

    'paths' => [
        'api/*',
        'sanctum/csrf-cookie',
        'login',
        'logout',
        'register',
        'forgot-password',
        'reset-password',
        'user',
    ],

    /*
    |--------------------------------------------------------------------------
    | Allowed HTTP Methods
    |--------------------------------------------------------------------------
    |
    | Use '*' to allow all standard HTTP methods. Restrict if needed.
    |
    */

    'allowed_methods' => ['*'],

    /*
    |--------------------------------------------------------------------------
    | Allowed Origins
    |--------------------------------------------------------------------------
    |
    | These are the URLs your frontend may run from. Sanctum requires exact
    | match (scheme + domain + port). Include all local and production origins.
    |
    */

    'allowed_origins' => env('APP_ENV') === 'local'
        ? [
            'http://localhost:5173',
            'http://127.0.0.1:5173',
            'http://[::1]:5173',
            'http://tickerwolf.test',
            'https://tickerwolf.test',
        ]
        : [
            'https://tickerwolf.ai',
            'https://www.tickerwolf.ai',
        ],

    /*
    |--------------------------------------------------------------------------
    | Allowed Origins Patterns
    |--------------------------------------------------------------------------
    |
    | Leave empty if you’re specifying origins explicitly above.
    |
    */

    'allowed_origins_patterns' => [],

    /*
    |--------------------------------------------------------------------------
    | Allowed Headers
    |--------------------------------------------------------------------------
    |
    | Set to '*' unless you have a strict API policy.
    |
    */

    'allowed_headers' => ['*'],

    /*
    |--------------------------------------------------------------------------
    | Exposed Headers
    |--------------------------------------------------------------------------
    |
    | Headers that browsers are allowed to access in API responses.
    |
    */

    'exposed_headers' => [],

    /*
    |--------------------------------------------------------------------------
    | Max Age
    |--------------------------------------------------------------------------
    |
    | How long the results of a preflight request (OPTIONS) may be cached.
    |
    */

    'max_age' => 3600,

    /*
    |--------------------------------------------------------------------------
    | Supports Credentials
    |--------------------------------------------------------------------------
    |
    | Must be true for Sanctum’s cookie-based SPA authentication to work.
    |
    */

    'supports_credentials' => true,

];