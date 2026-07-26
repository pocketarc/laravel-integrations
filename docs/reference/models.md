# Models

All models live in the `Integrations\Models` namespace.

## Integration

The central model. Represents a configured connection to an external service.

### Relationships

| Relationship | Type | Target |
|-------------|------|--------|
| `requests()` | hasMany | `IntegrationRequest` |
| `logs()` | hasMany | `IntegrationLog` |
| `mappings()` | hasMany | `IntegrationMapping` |
| `webhooks()` | hasMany | `IntegrationWebhook` |
| `syncItems()` | hasMany | `IntegrationSyncItem` |
| `incidents()` | hasMany | `IntegrationIncident` |
| `owner()` | morphTo | Polymorphic (Team, User, etc.) |

### Methods

| Method | Description |
|--------|-------------|
| `at($endpoint)` | Open the fluent request builder. Chain `->as(SomeData::class)` to type the response. |
| `request()` | Lower-level direct API request entry point (the builder funnels into this). |
| `currentContext()` | Static. Read the active `RequestContext` from inside a closure when the closure can't take it as an argument. See [Making requests](/core-concepts/making-requests#requestcontext-in-closures). |
| `currentSyncAttempt()` | Static. Read the active `SyncAttemptContext` from inside a sync-item listener. `null` outside a sync item. See [terminal-vs-transient failures](/core-concepts/logging#terminal-vs-transient-failures). |
| `logOperation()` | Create an operation log entry |
| `failureSummary($since)` | Failure report (per-operation counts, rate, last error, per-status / per-`FailureClass` breakdowns) over a window. See [Failure summary](/core-concepts/health-monitoring#failure-summary). |
| `incidents()` | The integration's [incident history](/core-concepts/health-monitoring#incident-history) relation. The `current_incident` (`?IntegrationIncident`) and `has_open_incident` (`bool`) accessors read the loaded collection. |
| `mapExternalId()` | Claim an external ID for an internal model. Throws `MappingAlreadyClaimed` if another model holds it. See [Claiming and re-pointing](/features/id-mapping#claiming-and-re-pointing). |
| `remapExternalId()` | Move a mapping to a different internal model deliberately |
| `resolveMapping()` | Resolve external ID to internal model (returns typed `TModel`) |
| `resolveMappings()` | Batch-resolve multiple external IDs in two queries |
| `upsertByExternalId()` | Resolve, create-or-update, and map in one call. Serialised per external ID; see [Concurrency](/features/id-mapping#concurrency). |
| `findExternalId()` | Find external ID for an internal model |
| `getAccessToken()` | Get OAuth access token (auto-refreshes) |
| `tokenExpiresSoon()` | Check if token needs refresh |
| `refreshTokenIfNeeded()` | Explicitly refresh token |
| `markSynced()` | Update sync timestamps |
| `recordSuccess()` | Record a successful request |
| `recordFailure()` | Record a failed request |
| `credentialsArray()` | Get raw credentials array |
| `pendingSyncItemCount()` | Count of sync items still in flight (`pending` or `processing`) |

### Query scopes

| Scope | Description |
|-------|-------------|
| `ownedBy($model)` | Filter by polymorphic owner |

## IntegrationRequest

Represents a single API request/response.

### Relationships

| Relationship | Type | Target |
|-------------|------|--------|
| `integration()` | belongsTo | `Integration` |
| `related()` | morphTo | Polymorphic (linked model) |
| `retryOf()` | belongsTo | `IntegrationRequest` (original attempt) |

### Notable properties

| Property | Type | Description |
|----------|------|-------------|
| `idempotency_key` | `?string` | Idempotency key, if one was set via `withIdempotencyKey()`. See [Idempotency](/core-concepts/idempotency). |
| `provider_request_id` | `?string` | Upstream's request ID (e.g. Stripe `Request-Id`, GitHub `X-GitHub-Request-Id`). Populated by adapter closures via `RequestContext::reportResponseMetadata()`. Useful when filing support tickets against the provider. |
| `failure_class` | `?FailureClass` | The classified failure on the failure path; `null` on success. See [What counts as a failure](/advanced/circuit-breaker#what-counts-as-a-failure). |

### Query scopes

| Scope | Description |
|-------|-------------|
| `successful()` / `failed()` | Filter by `response_success` |
| `forEndpoint($endpoint)` | Filter by endpoint |
| `withFailureClass($class)` | Filter by persisted `FailureClass` |
| `recent($hours)` / `since($since)` | Created within the last N hours / at or after a `CarbonInterface` |

### Testing methods

| Method | Description |
|--------|-------------|
| `fake()` | Activate the testing fake |
| `stopFaking()` | Deactivate the testing fake |
| `assertRequested()` | Assert an endpoint was called |
| `assertNotRequested()` | Assert an endpoint was not called |
| `assertRequestedWith()` | Assert with custom assertion |
| `assertRequestCount()` | Assert total request count |
| `assertNothingRequested()` | Assert no requests were made |

## IntegrationLog

Represents an operation-level log entry (sync, import, webhook processing).

### Relationships

| Relationship | Type | Target |
|-------------|------|--------|
| `integration()` | belongsTo | `Integration` |
| `parent()` | belongsTo | `IntegrationLog` |
| `children()` | hasMany | `IntegrationLog` |

### Query scopes

| Scope | Description |
|-------|-------------|
| `successful()` | Where status is success |
| `failed()` | Where status is failed |
| `forOperation($op)` | Filter by operation type |
| `topLevel()` | Where parent_id is null |
| `recent($hours)` | Created within the last N hours |
| `since($since)` | Created at or after a `CarbonInterface` |

### Status constants

`STATUS_SUCCESS`, `STATUS_FAILED`, `STATUS_PROCESSING`, `STATUS_PARTIAL`, `STATUS_DEFERRED`. The documented `status` vocabulary; the column stays a free string. Only `success` / `failed` / `processing` dispatch events; `partial` (a sync run with some failed items) and `deferred` are recorded silently.

## IntegrationMapping

Maps external provider IDs to internal Eloquent models.

### Relationships

| Relationship | Type | Target |
|-------------|------|--------|
| `integration()` | belongsTo | `Integration` |
| `internal()` | morphTo | Polymorphic (mapped model) |

## IntegrationWebhook

Stores received webhook payloads for audit and replay.

### Relationships

| Relationship | Type | Target |
|-------------|------|--------|
| `integration()` | belongsTo | `Integration` |

## IntegrationSyncItem

One row per item dispatched during a sync run. The framework uses these to track per-item completion and decide when the cursor can advance. See [Scheduled syncs](/features/scheduled-syncs).

### Relationships

| Relationship | Type | Target |
|-------------|------|--------|
| `integration()` | belongsTo | `Integration` |
| `syncLog()` | belongsTo | `IntegrationLog` |

### Methods

| Method | Description |
|--------|-------------|
| `isTerminal()` | Whether the item reached a terminal state (`success`, `failed`, or `skipped`) |

### Query scopes

| Scope | Description |
|-------|-------------|
| `pending()` / `processing()` / `successful()` / `failed()` / `skipped()` | Filter by status |
| `inFlight()` | Items not yet terminal (`pending` or `processing`) |
| `forBatch($batchId)` | Items in a Bus batch |
| `forSyncLog($syncLogId)` | Items belonging to one sync run |
| `forIntegration($integrationId)` | Items for one integration |

### Status constants

`STATUS_PENDING`, `STATUS_PROCESSING`, `STATUS_SUCCESS`, `STATUS_FAILED`, `STATUS_SKIPPED`.

## IntegrationIncident

A durable record of one period an integration was in trouble, written from health/circuit state-change events. See [Incident history](/core-concepts/health-monitoring#incident-history).

### Relationships

| Relationship | Type | Target |
|-------------|------|--------|
| `integration()` | belongsTo | `Integration` |

### Methods

| Method | Description |
|--------|-------------|
| `isOpen()` | Whether the incident is still open |

### Query scopes

| Scope | Description |
|-------|-------------|
| `open()` / `closed()` | Filter by status |
| `forIntegration($id)` | Incidents for one integration |
| `since($since)` | Opened at or after a `CarbonInterface` |

### Constants

`STATUS_OPEN`, `STATUS_CLOSED`, `SOURCE_HEALTH`, `SOURCE_CIRCUIT`.
