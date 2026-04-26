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

        // Queue name for webhook processing jobs. All webhooks are processed asynchronously.
        'queue' => 'default',

        // Maximum webhook payload size in bytes. Payloads exceeding this limit are rejected
        // with a 413 response. Prevents storage bloat from oversized or malicious payloads.
        'max_payload_bytes' => 1_048_576, // 1MB

        // Maximum time (in seconds) a webhook can remain in "processing" status before
        // it is considered stale and eligible for recovery by integrations:recover-webhooks.
        // If a queue worker dies mid-processing, the webhook gets stuck; this timeout
        // allows automatic recovery. Minimum 60 seconds.
        'processing_timeout' => 1800, // 30 minutes

        // Additional middleware applied to webhook routes. Webhook routes intentionally
        // have no middleware by default - most webhook providers can't handle CSRF tokens
        // or session-based auth. Add signature verification middleware here if needed.
        'middleware' => [],
    ],

    'oauth' => [
        // URL prefix for OAuth routes: GET /{prefix}/{id}/oauth/authorize, GET /{prefix}/oauth/callback
        'route_prefix' => 'integrations',

        // Middleware for OAuth authorize and revoke routes. These are user-initiated actions
        // that need session, CSRF, and typically app authentication.
        'middleware' => ['web'],

        // Middleware for the OAuth callback route. This is a redirect back from the external
        // provider, so it cannot carry session-based app auth. Keep this minimal.
        'callback_middleware' => ['web'],

        // Where to redirect the user after a successful OAuth authorization callback.
        'success_redirect' => '/integrations',

        // How long (in seconds) the OAuth state parameter remains valid. This is the
        // maximum time between the user clicking "Connect" and completing the OAuth flow
        // on the provider's site. 10 minutes is generous but prevents stale state attacks.
        'state_ttl' => 600,

        // Lock TTL (seconds) when refreshing OAuth tokens. Prevents concurrent
        // refresh attempts from multiple queue workers.
        'refresh_lock_ttl' => 30,

        // Maximum seconds to wait for the refresh lock before throwing LockTimeoutException.
        'refresh_lock_wait' => 15,
    ],

    'sync' => [
        // Default queue name for dispatched sync jobs.
        'queue' => 'default',

        // Per-provider queue overrides. Keys are provider identifiers (matching the
        // Integration model's `provider` column). Values are queue names. If a provider
        // is not listed here, sync.queue is used.
        'queues' => [],

        // Maximum time (in seconds) a sync job can hold its WithoutOverlapping lock.
        // Prevents a crashed sync from blocking all future syncs for that integration.
        // Should be longer than your slowest expected sync.
        'lock_ttl' => 600,
    ],

    'retry' => [
        // Maximum seconds to honour a Retry-After header. Prevents a misbehaving API
        // from blocking a worker indefinitely. Retry-After values exceeding this cap
        // are clamped to this value.
        'retry_after_max_seconds' => 600,
    ],

    'rate_limiting' => [
        // Maximum seconds to wait for rate limit capacity before throwing
        // RateLimitExceededException. When set to 0, throws immediately without waiting.
        // When > 0, sleeps in 1-second intervals and re-checks until capacity is available
        // or the max wait time is exceeded.
        'max_wait_seconds' => 10,
    ],

    'circuit_breaker' => [
        // When enabled, integrations that fail repeatedly are short-circuited for a
        // cooldown window so we don't hammer a service that's clearly down. Failures
        // counted: 5xx responses, connection errors, and any RetryableException.
        // Failures NOT counted: 4xx (client error, retrying won't help), and a
        // CircuitOpenException itself.
        'enabled' => true,

        // Number of consecutive failures before the breaker opens. Once open, all
        // requests for this integration throw CircuitOpenException until the cooldown
        // window passes.
        'threshold' => 5,

        // Seconds to keep the breaker open after it trips. Once this elapses, the
        // next request becomes a half-open probe: if it succeeds, the breaker
        // closes; if it fails, the breaker re-opens for another full cooldown.
        'cooldown_seconds' => 60,
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

        // Number of consecutive failures before an integration is automatically disabled
        // (is_active set to false, health_status set to "disabled"). Set to null to disable
        // this feature. Once disabled, re-enabling requires manual intervention.
        'disabled_after' => 50,
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

];
