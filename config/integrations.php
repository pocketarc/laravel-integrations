<?php

declare(strict_types=1);

return [
    // Prefix for all database tables: {prefix}s, {prefix}_requests, {prefix}_logs, {prefix}_mappings.
    'table_prefix' => 'integration',

    // Prefix for all cache keys used by this package (e.g. OAuth state tokens).
    'cache_prefix' => 'integrations',

    'webhook' => [
        // URL prefix for webhook routes: POST /{prefix}/{provider}/webhook
        'prefix' => 'integrations',

        // Additional middleware applied to webhook routes. Webhook routes intentionally
        // have no middleware by default - most webhook providers can't handle CSRF tokens
        // or session-based auth. Add signature verification middleware here if needed.
        'middleware' => [],
    ],

    'oauth' => [
        // URL prefix for OAuth routes: GET /{prefix}/{id}/oauth/authorize, GET /{prefix}/oauth/callback
        'route_prefix' => 'integrations',

        // OAuth routes need session and CSRF protection because they involve user-facing
        // browser redirects, unlike webhooks which are server-to-server.
        'middleware' => ['web'],

        // Where to redirect the user after a successful OAuth authorization callback.
        'success_redirect' => '/integrations',

        // How long (in seconds) the OAuth state parameter remains valid. This is the
        // maximum time between the user clicking "Connect" and completing the OAuth flow
        // on the provider's site. 10 minutes is generous but prevents stale state attacks.
        'state_ttl' => 600,
    ],

    'sync' => [
        // Queue name for dispatched sync jobs.
        'queue' => 'default',

        // Maximum time (in seconds) a sync job can hold its WithoutOverlapping lock.
        // Prevents a crashed sync from blocking all future syncs for that integration.
        // Should be longer than your slowest expected sync.
        'lock_ttl' => 600,
    ],

    'rate_limiting' => [
        // When enabled, Integration::request() checks actual request counts from the
        // integration_requests table before each call, and throws RateLimitExceededException
        // if the provider's rate limit would be exceeded. Disable if you handle rate
        // limiting externally or don't need it.
        'enabled' => true,
    ],

    'request_logging' => [
        // When enabled, every Integration::request() call is persisted to the
        // integration_requests table. This powers rate limiting, caching, health tracking,
        // and the integrations:health command. Disable only if you need to reduce write load
        // and don't need these features.
        'enabled' => true,

        // When enabled, Integration::request() checks for a matching unexpired cached response
        // before making the actual call. Cached responses are identified by matching
        // integration + endpoint + method + request_data_hash. Only applies when the caller
        // passes a cacheFor parameter.
        'cache_enabled' => true,
    ],

    'health' => [
        // Number of consecutive failed requests before an integration is marked "degraded".
        // Degraded integrations sync at a reduced frequency (see degraded_backoff).
        'degraded_after' => 5,

        // Number of consecutive failed requests before an integration is marked "failing".
        // Failing integrations sync at a heavily reduced frequency (see failing_backoff).
        'failing_after' => 20,

        // Sync interval multiplier when degraded. A value of 2 means an integration that
        // normally syncs every 5 minutes will sync every 10 minutes instead.
        'degraded_backoff' => 2,

        // Sync interval multiplier when failing. A value of 10 means an integration that
        // normally syncs every 5 minutes will sync every 50 minutes instead. This prevents
        // hammering a service that's consistently down.
        'failing_backoff' => 10,
    ],

    'pruning' => [
        // Delete integration_requests older than this many days when running integrations:prune.
        'requests_days' => 90,

        // Delete integration_logs older than this many days when running integrations:prune.
        // Logs are kept longer than requests because they represent business operations
        // (syncs, imports) rather than individual API calls.
        'logs_days' => 365,

        // Number of rows to delete per batch. Deleting in chunks avoids holding a table lock
        // for the entire duration of a large delete, keeping the table responsive for normal
        // operations while pruning runs.
        'chunk_size' => 1000,
    ],

    // Register integration providers. Keys are the provider identifier used in the
    // Integration model's `provider` column. Values are the fully-qualified class names
    // that implement IntegrationProvider. Can also be registered programmatically via
    // Integrations::register('zendesk', ZendeskProvider::class).
    'providers' => [],

    // Maps provider key to a Spatie LaravelData class for typed credential access.
    // e.g. 'zendesk' => App\Data\ZendeskCredentials::class
    // When set, $integration->credentials returns an instance of the Data class
    // instead of a plain array.
    'credential_data_classes' => [],

    // Maps provider key to a Spatie LaravelData class for typed metadata access.
    // e.g. 'zendesk' => App\Data\ZendeskMetadata::class
    'metadata_data_classes' => [],
];
