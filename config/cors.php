<?php

return [
    'paths' => ['api/*'],

    'allowed_methods' => ['*'],

    // V1 has no browser client that requires cross-origin API access.
    // Add trusted origins explicitly only when a reviewed requirement exists.
    'allowed_origins' => [],

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => false,
];
