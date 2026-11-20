<?php

$allowedOrigins = array_values(array_filter(array_map(
    'trim',
    explode(',', env('CORS_ALLOWED_ORIGINS', 'http://localhost:5173'))
)));

return [

    /*
    |--------------------------------------------------------------------------
    | CORS Paths
    |--------------------------------------------------------------------------
    |
    | The API routes and Sanctum's CSRF cookie route must allow cross-origin
    | requests from the configured first-party SPA origins.
    |
    */

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    /*
    |--------------------------------------------------------------------------
    | Allowed Methods
    |--------------------------------------------------------------------------
    */

    'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],

    /*
    |--------------------------------------------------------------------------
    | Allowed Origins
    |--------------------------------------------------------------------------
    |
    | Comma-separated origins are supported through CORS_ALLOWED_ORIGINS so
    | production domains do not need to be hard-coded in the repository.
    |
    */

    'allowed_origins' => $allowedOrigins,

    'allowed_origins_patterns' => [],

    /*
    |--------------------------------------------------------------------------
    | Allowed Headers
    |--------------------------------------------------------------------------
    */

    'allowed_headers' => [
        'Accept',
        'Authorization',
        'Content-Type',
        'Origin',
        'Referer',
        'X-CSRF-TOKEN',
        'X-Requested-With',
        'X-XSRF-TOKEN',
    ],

    /*
    |--------------------------------------------------------------------------
    | Exposed Headers
    |--------------------------------------------------------------------------
    |
    | XSRF-TOKEN is exposed so the SPA can read it and return it as the
    | X-XSRF-TOKEN header on state-changing requests.
    |
    */

    'exposed_headers' => ['X-XSRF-TOKEN'],

    'max_age' => 0,

    'supports_credentials' => true,

];
