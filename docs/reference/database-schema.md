# Database schema

The package creates five tables with a configurable prefix (default: `integration`). Publish and run migrations with:

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
| `last_synced_at` | timestamp (nullable) | When the last sync completed |
| `next_sync_at` | timestamp (nullable) | When the next sync should run |
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
| `response_data` | text (nullable) | Response body (redacted if applicable) |
| `error` | text (nullable) | Error message on failure |
| `duration_ms` | int (nullable) | Request duration in milliseconds |
| `retry_of` | bigint (nullable) | Points to the original request if this is a retry |
| `related_type` | string (nullable) | Polymorphic related model type |
| `related_id` | bigint (nullable) | Polymorphic related model ID |
| `cache_hits` | int | Number of cache hits for this response |
| `stale_hits` | int | Number of stale cache fallbacks |
| `timestamps` | | `created_at`, `updated_at` |

## integration_logs

Operation-level logs (syncs, imports, webhooks).

| Column | Type | Description |
|--------|------|-------------|
| `id` | bigint (PK) | Auto-incrementing ID |
| `integration_id` | bigint (FK) | Parent integration |
| `operation` | string | Operation type (sync, import, webhook, etc.) |
| `direction` | string | `inbound` or `outbound` |
| `status` | string | `success` or `failed` |
| `summary` | string (nullable) | Human-readable summary |
| `external_id` | string (nullable) | External record ID |
| `metadata` | json (nullable) | Structured metadata (counts, request IDs, etc.) |
| `duration_ms` | int (nullable) | Operation duration |
| `parent_id` | bigint (nullable) | For hierarchical logging |
| `timestamps` | | `created_at`, `updated_at` |

## integration_mappings

External ID to internal model mapping.

| Column | Type | Description |
|--------|------|-------------|
| `id` | bigint (PK) | Auto-incrementing ID |
| `integration_id` | bigint (FK) | Parent integration |
| `external_id` | string | External provider ID |
| `internal_type` | string | Internal model class |
| `internal_id` | bigint | Internal model ID |
| `timestamps` | | `created_at`, `updated_at` |

Unique constraint on `(integration_id, external_id, internal_type)`.

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
