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

    'mappings' => [
        // Lock TTL (seconds) held while upsertByExternalId() creates a model and
        // claims its external ID. Two workers syncing the same external record
        // would otherwise each insert a row, and only one could hold the
        // mapping; the other was left unreachable. The lock needs a shared cache
        // driver to do anything: on the `array` driver it is per-process, and
        // the collision is caught and converged on instead.
        'lock_ttl' => 10,

        // Maximum seconds to wait for that lock before throwing LockTimeoutException.
        'lock_wait' => 10,

        // Collation for integration_mappings' string columns. MySQL/MariaDB
        // only, ignored elsewhere. Leave null to inherit the connection default.
        //
        // Set this when your own tables use a different collation from the one
        // Laravel gives this package's, because comparing internal_id against
        // your primary keys then fails with "Illegal mix of collations" — which
        // is exactly the query you want when hunting rows that lost a mapping.
        // Match whatever your domain tables use, e.g. 'utf8mb4_general_ci'.
        'collation' => null,
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
        // Must be at least as long as sync.job_timeout so the lock outlasts the job
        // that holds it; otherwise the lock can auto-expire mid-sync and let a
        // sibling dispatch start running concurrently.
        'lock_ttl' => 1800,

        // Maximum time (in seconds) the SyncIntegration job can run before the queue
        // worker SIGKILLs it. 30 minutes is generous for first-run backfills (e.g. a
        // 30-day Zendesk ticket window or GitHub multi-page issue scan) without
        // holding a worker forever if a sync gets stuck. integrations:sync reads this
        // and passes it on dispatch; direct callers can still override per-call via
        // the SyncIntegration constructor.
        'job_timeout' => 1800,

        // Queue for the per-item ProcessSyncItem jobs. Each item a provider hands to
        // the SyncSession becomes one of these jobs; it runs the item's listeners and
        // records completion so the cursor only advances past finished work. Leave
        // null to use the same queue as the parent sync job (sync.queue / sync.queues).
        'item_queue' => null,

        // How many times a ProcessSyncItem's listeners may throw before the item is
        // marked "failed" and the job lands in failed_jobs. Transient rate-limit
        // deferrals are not counted; only genuine listener exceptions are. Each
        // attempt re-runs the listeners, so listeners must be idempotent.
        'item_tries' => 5,

        // Backoff schedule (seconds between retries) for ProcessSyncItem jobs.
        'item_backoff' => [10, 30, 120, 300, 900],

        // Absolute wall-clock window (seconds) a ProcessSyncItem may keep retrying,
        // including transient rate-limit deferrals, before the queue gives up. Set
        // generously so an item throttled across several of a provider's rate-limit
        // windows still completes; a genuinely broken item is bounded sooner by
        // item_tries (which only counts real listener exceptions).
        'item_retry_window' => 21600, // 6 hours

        // Soft cap on the number of items in one sync run. A run that enumerates
        // more than this is still processed as a single Bus batch, but
        // SyncIntegration logs a warning so you can narrow the sync window or
        // page the provider more aggressively.
        'max_items_per_batch' => 10000,
    ],

    'retry' => [
        // Maximum seconds to honour a Retry-After header. Prevents a misbehaving API
        // from blocking a worker indefinitely. Retry-After values exceeding this cap
        // are clamped to this value.
        'retry_after_max_seconds' => 600,
    ],

    'rate_limiting' => [
        // Maximum seconds the rate limiter will sleep waiting for capacity before
        // throwing RateLimitExceededException. 0 = never sleep, throw immediately.
        // Applies independently to the provider-fed suppression gate and the local
        // window bucket. A throw inside a sync defers the item (re-queued, see
        // sync.item_retry_window); for other callers it surfaces as an exception.
        'max_wait_seconds' => 10,

        // Master switch for per-integration rate-limit overrides (set at runtime via
        // $integration->overrideRateLimit(...) or `integrations:rate-limit`). When
        // false the override columns still accept writes but are ignored, falling
        // back to the provider's defaultRateLimit().
        'overrides_enabled' => true,
    ],

    'circuit_breaker' => [
        // When enabled, integrations whose upstream is failing are short-circuited
        // for a cooldown window so we don't hammer a service that's clearly down.
        // Only *upstream* faults count toward tripping: 5xx (except 501), connection
        // errors, and timeouts. A 429 (the upstream is healthy, just pacing us),
        // other 4xx client errors, and unrecognised exceptions do NOT count. See
        // src/Support/FailureClassifier.php for the classification.
        'enabled' => true,

        // Tripping strategy:
        //   'rate'  (default) — open when the failure rate over `time_window` crosses
        //                       `failure_rate_threshold` percent, once `minimum_requests`
        //                       have been seen. Best for steady traffic.
        //   'count'           — open after `threshold` consecutive upstream failures.
        // NOTE: under 'rate', a low-volume integration whose request count stays below
        // `minimum_requests` within a window will not trip. Use 'count' if you need
        // volume-independent tripping (e.g. a quiet scheduled sync).
        'strategy' => 'rate',

        // --- rate strategy ---
        'time_window' => 60,            // rolling failure window, seconds
        'failure_rate_threshold' => 50, // percent (1-100) of failures that opens the breaker
        'minimum_requests' => 10,       // floor of requests in the window before the rate can trip

        // --- count strategy ---
        // Number of consecutive upstream failures before the breaker opens.
        'threshold' => 5,

        // Seconds to keep the breaker open after it trips. Once this elapses, the
        // next request becomes a half-open probe: if it succeeds, the breaker
        // closes; if it fails, the breaker re-opens for another full cooldown.
        'cooldown_seconds' => 60,

        // Master switch for per-integration circuit overrides (force open/closed/
        // disabled at runtime via $integration->forceCircuitOpen(...) or
        // `integrations:circuit`). When false the override columns still accept
        // writes but are ignored.
        'overrides_enabled' => true,
    ],

    'observability' => [
        // Failure-anomaly evaluation, run by `integrations:evaluate-failures` (schedule
        // it yourself, e.g. ->everyFifteenMinutes()). It measures each active
        // integration's failure rate over a rolling window from the persisted
        // integration_requests rows and emits one ElevatedFailureRate event per
        // incident (debounced) plus a FailureRateRecovered event when it clears, so a
        // consumer can fire a single alert per incident instead of one per failure.
        // Thresholds live here; where alerts go (Sentry, Slack) stays in the consumer.
        // This is separate from the circuit breaker's own window: the breaker protects
        // the upstream, this watches for an alert-worthy spike.
        'anomaly_enabled' => true,

        // Rolling window, in minutes, the failure rate is measured over.
        'anomaly_window_minutes' => 15,

        // Failure-rate percentage (1-100) at or above which an anomaly fires.
        'anomaly_failure_rate_threshold' => 25,

        // Minimum number of requests in the window before the rate can fire, so a
        // couple of failures in an otherwise quiet window don't raise an alert.
        'anomaly_minimum_requests' => 20,

        // Master switch for the durable incident audit (the integration_incidents table).
        // When enabled, the package opens one incident per integration from its own
        // health/circuit state-change events, folds flapping into it (tracking peak
        // severity), and closes it on recovery. Circuit state is cache-only and
        // ephemeral; this persists the history so "incidents since T" is answerable.
        'incidents_enabled' => true,
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

    'logging' => [
        // Cap on stored response bodies, in bytes; null keeps them whole.
        // Request bodies are already cut at 64KB, and an oversized response is
        // the main reason integration_requests outgrows every other table.
        // Truncation keeps the head, where the useful part of a debugging read
        // usually is.
        //
        // Bodies the package reads back are exempt whatever this is set to: a
        // cached response is the cache's payload, and an idempotent write's
        // response backs IdempotencyConflict recovery. A provider can also opt
        // individual endpoints out of body storage entirely with the
        // LimitsRequestLogging contract.
        'max_response_bytes' => null,
    ],

    'pruning' => [
        // Delete integration_requests older than this many days when running integrations:prune.
        'requests_days' => 90,

        // Delete integration_logs older than this many days when running integrations:prune.
        // Logs are kept longer than requests because they represent business operations
        // (syncs, imports) rather than individual API calls.
        'logs_days' => 365,

        // Delete integration_idempotency_keys older than this many days when running
        // integrations:prune. An idempotency-key row is the "we already did this" marker
        // written by `at()->withIdempotencyKey($key)->post(...)`; pruning it allows the
        // same key to run again, so set this comfortably longer than your longest queue
        // retry window. Defaults to match requests_days so a single retention knob covers
        // most setups.
        'idempotency_keys_days' => 90,

        // Delete completed integration_sync_items (status "success" or "skipped") older
        // than this many days when running integrations:prune. Rows still in "failed"
        // status are kept indefinitely so an operator can find and recover them; clear
        // them by resolving the item (retry or skip) before they age out.
        'sync_items_days' => 30,

        // Delete closed integration_incidents older than this many days (by closed_at)
        // when running integrations:prune. Open incidents are live state and are never
        // pruned. Incidents are audit history, so kept a year by default.
        'incidents_days' => 365,

        // Safety net: when running integrations:prune, auto-close any incident left open
        // longer than this many days for an integration that is currently healthy. Covers
        // the case where a CircuitClosed event never fired because the cache state expired.
        'incidents_stale_after_days' => 7,

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
