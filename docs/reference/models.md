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
| `owner()` | morphTo | Polymorphic (Team, User, etc.) |

### Methods

| Method | Description |
|--------|-------------|
| `request()` | Make an untyped API request |
| `requestAs()` | Make a typed API request |
| `to()` / `toAs()` | Fluent request builder |
| `logOperation()` | Create an operation log entry |
| `mapExternalId()` | Map an external ID to an internal model |
| `resolveMapping()` | Resolve external ID to internal model (returns typed `TModel`) |
| `resolveMappings()` | Batch-resolve multiple external IDs in two queries |
| `upsertByExternalId()` | Resolve, create-or-update, and map in one call |
| `findExternalId()` | Find external ID for an internal model |
| `getAccessToken()` | Get OAuth access token (auto-refreshes) |
| `tokenExpiresSoon()` | Check if token needs refresh |
| `refreshTokenIfNeeded()` | Explicitly refresh token |
| `markSynced()` | Update sync timestamps |
| `recordSuccess()` | Record a successful request |
| `recordFailure()` | Record a failed request |
| `credentialsArray()` | Get raw credentials array |

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
