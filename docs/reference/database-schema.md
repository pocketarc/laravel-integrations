# Database schema

The package creates several tables with a configurable prefix (default: `integration`). Publish and run migrations with:

```bash
php artisan vendor:publish --tag=integrations-migrations
php artisan migrate
```

## integrations

The main table storing integration records.

| Column | Type | Description |
|--------|------|-------------|
| `id` | bigint (PK) | Auto-incrementing ID |
| `provider` | string | Provider identifier (matches config key) |
| `name` | string | Human-readable name |
| `credentials` | text (encrypted) | Provider credentials, encrypted at rest |
| `metadata` | json (nullable) | Non-sensitive configuration |
| `owner_type` | string (nullable) | Polymorphic owner type (for multi-tenancy) |
| `owner_id` | bigint (nullable) | Polymorphic owner ID |
| `is_active` | boolean | Whether the integration is active |
| `health_status` | string | Current health: healthy, degraded, failing, disabled |
| `consecutive_failures` | int | Running failure counter |
| `last_error_at` | timestamp (nullable) | When the last error occurred |
| `anomaly_alerted_at` | timestamp (nullable) | Set while the anomaly evaluator considers the failure rate elevated; the durable open/closed marker for the anomaly signal |
| `last_synced_at` | timestamp (nullable) | When the last sync completed with no failed items. A run with failures does not move it, so it records the last time syncing worked end to end. Staleness is measured from it. |
| `next_sync_at` | timestamp (nullable) | When the next sync should run. Advances on every finalised run, clean or not. |
| `consecutive_sync_failures` | int | Runs that finalised with failures since the last clean one. Sets the multiplier for the `next_sync_at` backoff. Distinct from `consecutive_failures`, which counts API-boundary faults and resets on any successful request. |
| `sync_stale_alerted_at` | timestamp (nullable) | Set while the integration counts as stale; the durable open/closed marker for the staleness signal |
| `sync_interval_minutes` | int (nullable) | Override for provider's default interval |
| `sync_cursor` | json (nullable) | Incremental sync cursor |
| `timestamps` | | `created_at`, `updated_at` |

## integration_requests

API request/response log. One row per API call (including retries).

| Column | Type | Description |
|--------|------|-------------|
| `id` | bigint (PK) | Auto-incrementing ID |
| `integration_id` | bigint (FK) | Parent integration |
| `endpoint` | string | Logical endpoint or URL path |
| `method` | string | HTTP method or SDK operation |
| `status_code` | int (nullable) | HTTP status code |
| `request_data` | text (nullable) | Request body/params (redacted if applicable) |
| `idempotency_key` | string (nullable) | Idempotency key for the call. See [Idempotency](/core-concepts/idempotency). |
| `provider_request_id` | string (nullable) | Upstream's request ID (Stripe `Request-Id`, GitHub `X-GitHub-Request-Id`, etc.). |
| `response_data` | text (nullable) | Response body (redacted if applicable) |
| `error` | text (nullable) | Error message on failure |
| `failure_class` | string (nullable) | [`FailureClass`](/advanced/circuit-breaker#what-counts-as-a-failure) on the failure path (`upstream` / `throttle` / `client` / `unknown`); `null` on success |
| `duration_ms` | int (nullable) | Request duration in milliseconds |
| `retry_of` | bigint (nullable) | Points to the original request if this is a retry |
| `related_type` | string (nullable) | Polymorphic related model type |
| `related_id` | bigint (nullable) | Polymorphic related model ID |
| `cache_hits` | int | Number of cache hits for this response |
| `stale_hits` | int | Number of stale cache fallbacks |
| `timestamps` | | `created_at`, `updated_at` |

## integration_logs

Operation-level logs (syncs, imports, webhooks).

| Column           | Type              | Description                                                      |
|------------------|-------------------|------------------------------------------------------------------|
| `id`             | bigint (PK)       | Auto-incrementing ID                                             |
| `integration_id` | bigint (FK)       | Parent integration                                               |
| `operation`      | string            | Operation type (sync, import, webhook, etc.)                     |
| `direction`      | string            | `inbound` or `outbound`                                          |
| `status`         | string            | Free-form, e.g. `success`, `failed`, `processing`, `pending`     |
| `summary`        | string (nullable) | Human-readable summary                                           |
| `external_id`    | string (nullable) | External record ID                                               |
| `metadata`       | json (nullable)   | Structured metadata (counts, request IDs, etc.)                  |
| `result_data`    | json (nullable)   | Structured output from the operation                             |
| `error`          | text (nullable)   | Error message on failure                                         |
| `attempt`        | smallint (nullable) | Retry attempt when a failure was logged inside a sync item run; `null` otherwise |
| `max_attempts`   | smallint (nullable) | The configured retry ceiling at log time; `null` outside a sync item run |
| `duration_ms`    | int (nullable)    | Operation duration                                               |
| `parent_id`      | bigint (nullable) | For hierarchical logging                                         |
| `timestamps`     |                   | `created_at`, `updated_at`                                       |

## integration_mappings

External ID to internal model mapping.

| Column | Type | Description |
|--------|------|-------------|
| `id` | bigint (PK) | Auto-incrementing ID |
| `integration_id` | bigint (FK) | Parent integration |
| `external_id` | string (500) | External provider ID |
| `internal_type` | string | Internal model class |
| `internal_id` | string | Internal model ID |
| `timestamps` | | `created_at`, `updated_at` |

Unique constraint on `(integration_id, external_id, internal_type)`.

## integration_idempotency_keys

Idempotency-key ledger. One row per `(integration_id, key)` pair held by a keyed call (`at()->withIdempotencyKey($key)->post(...)`). See [Idempotency](/core-concepts/idempotency).

| Column           | Type        | Description                                                       |
|------------------|-------------|-------------------------------------------------------------------|
| `id`             | bigint (PK) | Auto-incrementing ID                                              |
| `integration_id` | bigint (FK) | Parent integration                                                |
| `key`            | string(191) | Application-supplied key, unique per integration                  |
| `timestamps`     |             | `created_at`, `updated_at`                                        |

Unique constraint on `(integration_id, key)`.

## integration_sync_items

One row per item dispatched during a sync run. Tracks whether the item's listeners completed, so the cursor only advances past finished items. See [Scheduled syncs](/features/scheduled-syncs).

| Column             | Type           | Description                                                                  |
|--------------------|----------------|------------------------------------------------------------------------------|
| `id`               | bigint (PK)    | Auto-incrementing ID                                                         |
| `integration_id`   | bigint (FK)    | Parent integration                                                           |
| `batch_id`         | string(36, nullable) | Bus batch UUID, set after dispatch; for ops/Horizon correlation only |
| `sync_log_id`      | bigint (FK, nullable) | Parent `integration_logs` row for the run                             |
| `event_class`      | string         | The per-item event class, for ops/debugging                                  |
| `external_id`      | string(500, nullable) | Adapter-provided external identifier, for ops/debugging               |
| `checkpoint_value` | json (nullable)| The cursor token this item represents; reduced into the next `sync_cursor`   |
| `status`           | string(16)     | `pending`, `processing`, `success`, `failed`, `skipped`                      |
| `error`            | text (nullable)| Exception message on terminal failure                                        |
| `attempts`         | smallint       | Attempt count at the last status change                                      |
| `completed_at`     | timestamp (nullable) | When the item reached a terminal state                                 |
| `timestamps`       |                | `created_at`, `updated_at`                                                   |

Indexed on `(integration_id, status)`, `(integration_id, created_at)`, `(sync_log_id, status)`, `(batch_id, status)`. Completed (`success` / `skipped`) rows are pruned by `integrations:prune`; `failed` rows are kept until resolved.

## integration_incidents

Durable audit of periods an integration was in trouble. One open row per integration, written from health/circuit state-change events; see [Incident history](/core-concepts/health-monitoring#incident-history).

| Column | Type | Description |
|--------|------|-------------|
| `id` | bigint (PK) | Auto-incrementing ID |
| `integration_id` | bigint (FK) | Parent integration |
| `status` | string(16) | `open` or `closed` |
| `source` | string(16) | What opened it: `health`, `circuit`, or `sync` |
| `reason` | string | Opening reason (e.g. `health_degraded`, `threshold_reached`) |
| `peak_severity` | string | Worst `HealthStatus` reached over the incident's life |
| `opened_at` | timestamp | When the incident opened |
| `last_error_at` | timestamp (nullable) | Snapshot of the integration's `last_error_at`, refreshed on escalate |
| `closed_at` | timestamp (nullable) | When the incident closed |
| `timestamps` | | `created_at`, `updated_at` |

Indexed on `(integration_id, status)` and `(integration_id, opened_at)`. Closed rows are pruned by `integrations:prune` (`pruning.incidents_days`, default 365); open rows are never pruned.

## integration_webhooks

Webhook audit trail.

| Column | Type | Description |
|--------|------|-------------|
| `id` | bigint (PK) | Auto-incrementing ID |
| `integration_id` | bigint (FK, nullable) | Parent integration (null for generic webhooks) |
| `provider` | string | Provider identifier |
| `event_type` | string (nullable) | Resolved event type |
| `delivery_id` | string (nullable) | Deduplication key |
| `payload` | text | Full webhook payload |
| `headers` | json (nullable) | Request headers |
| `status` | string | `pending`, `processing`, `processed`, `failed` |
| `processed_at` | timestamp (nullable) | When processing completed |
| `timestamps` | | `created_at`, `updated_at` |
