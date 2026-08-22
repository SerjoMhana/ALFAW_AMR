<?php

/**
 * Google Workspace integration.
 *
 * The service-account key is a file path, never the key itself: the JSON opens
 * the school's whole domain and must not sit in the repository or in config.
 */
return [
    'classroom' => [
        // Off until the school has finished the Workspace setup. While off, the
        // app uses an in-memory stand-in so the screens still work.
        'enabled' => (bool) env('GOOGLE_CLASSROOM_ENABLED', false),

        'credentials' => env('GOOGLE_SERVICE_ACCOUNT_JSON'),

        // The school's Workspace domain, e.g. vis.edu.ly
        'domain' => env('GOOGLE_WORKSPACE_DOMAIN'),

        // A real user the server acts as. Courses need a human owner; a service
        // account cannot own one.
        'impersonate' => env('GOOGLE_IMPERSONATE_EMAIL'),
    ],
];
