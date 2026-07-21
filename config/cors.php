<?php

return [
    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    'allowed_origins' => [
        'http://localhost:5174',
        'http://127.0.0.1:5174',
        'http://172.16.4.251:5174',
        'https://web.nyumbadirectonline.co.tz',
        'https://nyumbadirectonline.co.tz',
        'https://www.nyumbadirectonline.co.tz',
    ],

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => false,
];
