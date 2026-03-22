<?php

declare(strict_types=1);

return [
    'table_prefix' => 'integration',

    'webhook' => [
        'prefix' => 'integrations',
        'middleware' => [],
    ],

    'oauth' => [
        'route_prefix' => 'integrations',
        'middleware' => ['web'],
        'success_redirect' => '/integrations',
        'state_ttl' => 600,
    ],

    'sync' => [
        'queue' => 'default',
        'lock_ttl' => 600,
    ],

    'rate_limiting' => [
        'enabled' => true,
    ],

    'request_logging' => [
        'enabled' => true,
        'cache_enabled' => true,
    ],

    'health' => [
        'degraded_after' => 5,
        'failing_after' => 20,
        'degraded_backoff' => 2,
        'failing_backoff' => 10,
    ],

    'pruning' => [
        'requests_days' => 90,
        'logs_days' => 365,
        'chunk_size' => 1000,
    ],

    'providers' => [],

    'credential_data_classes' => [],

    'metadata_data_classes' => [],
];
