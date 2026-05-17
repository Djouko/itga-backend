<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Operational Readiness
    |--------------------------------------------------------------------------
    |
    | The readiness endpoint is intended for deployment platforms and internal
    | monitors. Set READINESS_TOKEN in staging and production so runtime
    | configuration details are not exposed publicly.
    |
    */

    'readiness_token' => env('READINESS_TOKEN', ''),

    'api_secret_key' => env('API_SECRET_KEY', ''),

    'admin_api_secret_key' => env('ADMIN_API_SECRET_KEY', ''),

    /*
    |--------------------------------------------------------------------------
    | Production Runtime Requirements
    |--------------------------------------------------------------------------
    |
    | Multi-node deployments must use shared stores. File cache/session drivers
    | and the sync queue are acceptable for local development only; at scale they
    | make rate limits inconsistent and move heavy work into user requests.
    |
    */

    'production_cache_drivers' => [
        'database',
        'redis',
        'memcached',
        'dynamodb',
    ],

    'production_session_drivers' => [
        'redis',
        'memcached',
        'database',
        'dynamodb',
    ],

    'production_queue_connections' => [
        'redis',
        'database',
        'beanstalkd',
        'sqs',
    ],

    'production_filesystem_disks' => [
        's3',
    ],
];
