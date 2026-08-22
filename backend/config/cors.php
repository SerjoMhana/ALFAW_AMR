<?php

/**
 * The API is consumed by this school's own frontend and nothing else, so the
 * allowed origins are named rather than left open. Add deployment hosts to
 * FRONTEND_URLS (comma separated) rather than widening this to '*'.
 */
$origins = array_values(array_filter(array_map(
    'trim',
    explode(',', (string) env('FRONTEND_URLS', 'http://localhost:5173,http://127.0.0.1:5173')),
)));

return [

    'paths' => ['api/*', 'sanctum/csrf-cookie', 'login', 'logout'],

    'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],

    'allowed_origins' => $origins,

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['Accept', 'Authorization', 'Content-Type', 'X-Requested-With', 'X-XSRF-TOKEN'],

    'exposed_headers' => [],

    'max_age' => 3600,

    // The SPA authenticates with a session cookie, which the browser only
    // attaches when credentials are allowed — and only ever to the named
    // origins above, never to '*'.
    'supports_credentials' => true,

];
