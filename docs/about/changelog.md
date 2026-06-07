# Changelog

All notable changes to this project are documented here. This project follows [Semantic Versioning](https://semver.org/).

## 5.3.0

A failure-observability layer on top of the existing resilience machinery: a way to alert on terminal failures only, a failure-summary API, a debounced anomaly signal, and a durable incident history. The schema changes (`integration_requests.failure_class`, `integration_logs.attempt` / `max_attempts`, and the new `integration_incidents` table) land in the canonical migration, so fresh installs get them on first migrate. Existing deployments need a downstream migration to add those columns and the table.

- New: [terminal-vs-transient failure semantics](/core-concepts/logging#terminal-vs-transient-failures). A listener that logs `failed` inside a [sync item](/features/scheduled-syncs) re-fires [`OperationFailed`](/reference/events#operationfailed) on every retry, even when the item later succeeds — the bulk of the alert noise, and not fixable in the consumer because the retry state lives in `ProcessSyncItem`. The job now exposes it: a `SyncAttemptContext` (plain `final readonly`) set around the per-item event and readable via `Integration::currentSyncAttempt()`, mirroring the existing `currentContext()` escape hatch. `logOperation()` stamps the attempt onto the new `integration_logs.attempt` / `max_attempts` columns and onto `OperationFailed` (a nullable third constructor argument — existing listeners are unaffected). Alert on terminal failures only by moving the forward to [`SyncItemFailed`](/reference/events#syncitemfailed), which still fires exactly once on exhaustion; `SyncAttemptContext::isLikelyFinalAttempt()` is a best-effort filter for operation-granularity `OperationFailed` hooks, never a substitute for it.
- New: a [failure-summary API](/core-concepts/health-monitoring#failure-summary). `Integration::failureSummary(CarbonInterface $since)` returns a `FailureSummary` (plain `final readonly`) with per-operation counts, distinct-item counts, failure rate, last error, and per-status / per-`FailureClass` breakdowns, computed from `integration_requests` and `integration_logs`. The [`integrations:health`](/reference/artisan-commands#integrations-health) and [`integrations:stats`](/reference/artisan-commands#integrations-stats) commands now render this summary, so the CLI and consumers report the same numbers. New `since()` request/log scopes back it.
- New: `integration_requests.failure_class` is now [persisted](/reference/database-schema#integration-requests) (set at execution time from the same [`FailureClassifier`](/advanced/circuit-breaker#what-counts-as-a-failure) verdict the breaker and health tracking use) so a per-class breakdown is queryable without re-classifying. New `withFailureClass()` scope and a `(integration_id, failure_class, created_at)` index.
- New: an [anomaly signal](/advanced/circuit-breaker#anomaly-signal) for alerting. [`integrations:evaluate-failures`](/reference/artisan-commands#integrations-evaluate-failures) measures each active integration's failure rate over a rolling window and dispatches a debounced [`ElevatedFailureRate`](/reference/events#elevatedfailurerate) — one event per incident, not one per failure — plus [`FailureRateRecovered`](/reference/events#failureraterecovered) when it clears. Schedule the command yourself; thresholds live in the new [`observability`](/reference/configuration#observability) config block, and where alerts go stays in the consumer. `CircuitBreaker::inspect()` also gains a `failure_rate` field exposing the breaker's live window rate.
- New: a durable [incident history](/core-concepts/health-monitoring#incident-history). The package records an `integration_incidents` audit row from its own `IntegrationHealthChanged` / `CircuitOpened` / `CircuitClosed` / `IntegrationDisabled` events — one open incident per integration into which both health and circuit signals fold (tracking peak severity), closed on recovery. Unlike the cache-only circuit state, this survives a `cache:clear`, so "incidents since T" is answerable. Read it with the `incidents()` relation and the `current_incident` / `has_open_incident` accessors; [`integrations:prune`](/reference/artisan-commands#integrations-prune) sweeps closed incidents ([`pruning.incidents_days`](/reference/configuration#pruning), default 365) and auto-closes stale-open ones for healthy integrations. Toggle with `observability.incidents_enabled`.
- New: documented `IntegrationLog::STATUS_*` constants (`success`, `failed`, `processing`, `deferred`) for the `status` field. The column stays a free string with the same event-dispatch behaviour; the constants are the documented vocabulary, nothing more.

## 5.2.0

- New: [provider-scoped passthrough](/testing/testing#provider-passthrough) on the testing fake. `IntegrationRequest::fake([...])->passthrough('openrouter')` lets the named provider's requests fall through to the real request executor instead of being served from the fake -- for when that provider is faked at a layer underneath `Integration::request()` (e.g. an AI call routed through the breaker and retries but stubbed at the SDK). Other providers stay faked. Opt-in and idempotent. Passthrough requests run for real and aren't recorded by default, so they don't appear in `assertRequested()`; add `recordPassthrough()` to log them for the assertions anyway. Unmatched requests for non-passthrough providers still return `null`.

## 5.1.0

- New: the [`DeclaresRateLimit`](/reference/contracts#declaresratelimit) provider contract carries `defaultRateLimit()` on its own, so a request-only provider can ship an in-code rate budget without implementing [`HasScheduledSync`](/reference/contracts#hasscheduledsync). The method moved up from `HasScheduledSync`, which now extends `DeclaresRateLimit`, so existing sync providers satisfy the new contract unchanged. `Integration::effectiveRateLimit()` reads any `DeclaresRateLimit` provider, and a [runtime override](/advanced/circuit-breaker#runtime-overrides) still takes precedence over the declared default. `make:integration-provider` gains a `--rate-limit` flag to scaffold a request-only provider's limit.
- New: the [`IdentifiesAuthenticatedUser`](/reference/contracts#identifiesauthenticateduser) provider contract resolves which account an integration's credentials authenticate as, the principal behind the token, mapped to a provider-agnostic [`AuthenticatedUser`](/features/authenticated-identity). Read it through `Integration::authenticatedUser()`, which makes the upstream "who am I" call through the request executor (so the breaker, rate limiter, and logging apply), caches the result with an optional `cacheFor` and `refresh`, and throws [`UnsupportedByProvider`](/features/authenticated-identity#reading-the-identity) when the provider doesn't implement the contract (pre-check with `supportsAuthenticatedUser()`). [`integrations:health`](/reference/artisan-commands#integrations-health) shows the resolved identity for providers that support it.

## 5.0.0

The circuit breaker is now an availability detector driven by a single failure classifier, and it can be controlled at runtime without a redeploy. See the [upgrade guide](/about/upgrade-guide) for the migration.

- Breaking: one [`FailureClassifier`](/advanced/circuit-breaker#what-counts-as-a-failure) decides what a failure means, and the same verdict feeds both the breaker and [health tracking](/core-concepts/health-monitoring). Only upstream faults (5xx except 501, connection errors, timeouts) count. HTTP 429 and other 4xx client errors no longer trip the breaker or degrade health: a throttle is the rate limiter's concern, and a malformed request from one caller can't pull an integration offline for everyone sharing it.
- Breaking: the breaker now defaults to a [rate strategy](/advanced/circuit-breaker#strategies) (failure percentage over a window) rather than a consecutive count. The [config block](/reference/configuration#circuit-breaker) gains `strategy`, `time_window`, `failure_rate_threshold`, and `minimum_requests`. Set `strategy` to `'count'` for the previous consecutive-failure behaviour, which is also the volume-independent choice for low-traffic integrations.
- Breaking: `Integration::recordFailure()` now takes a `FailureClass` argument.
- New: the [`ClassifiesFailures`](/reference/contracts#classifiesfailures) provider contract maps an SDK's exceptions to a failure class. Core also duck-types the common SDK status accessors (`getStatusCode()`, `getHttpStatus()`, `getHttpStatusCode()`, `getCode()` when it's a valid HTTP status, a wrapped PSR-7 response), so most SDKs classify correctly without it.
- New: [runtime overrides](/advanced/circuit-breaker#runtime-overrides) that force a circuit open, closed, or disabled, and override a rate limit, with optional expiry, via model helpers (`forceCircuitOpen()`, `overrideRateLimit()`, …) or the [`integrations:circuit`](/reference/artisan-commands#integrations-circuit) and [`integrations:rate-limit`](/reference/artisan-commands#integrations-rate-limit) commands. Backed by new columns on the integrations table, so they survive a `cache:clear`. Toggle globally with `circuit_breaker.overrides_enabled` / `rate_limiting.overrides_enabled`.
- New: [`CircuitOpened`](/reference/events#circuit-breaker) and [`CircuitClosed`](/reference/events#circuit-breaker) events fire on every transition (automatic or forced) with a reason, and a publishable `SendCircuitNotification` listener turns them into notifications. `integrations:health` and `integrations:list` now show breaker state.

## 4.2.0

- [`IdempotencyConflict`](/core-concepts/idempotency#recovering-on-conflict) now carries `$e->priorState` (an [`IdempotencyPriorState`](/core-concepts/idempotency#recovering-on-conflict) case: `NoRow`, `EmptyBody`, `Unparseable`, or `Recovered`) and `$e->priorRowId` alongside the existing `$e->priorResponse`. Catch blocks can now distinguish "no prior request on file" from "row exists but empty body" from "row exists but corrupt JSON", three failure modes that 4.1 collapsed into "priorResponse is null". Backward compatible: the new constructor arguments are added after `$priorResponse` with safe defaults, so existing positional callers keep working unchanged.
- New [`Integration::getIdempotencyRecovery(string $key): IdempotencyRecovery`](/core-concepts/idempotency#recovering-on-conflict) method that returns the same `(priorState, priorRowId, priorResponse)` shape attached to the exception. `getIdempotencyResponse()` from 4.1 becomes a thin wrapper that returns just the decoded array for callers that don't care about the state distinction.

## 4.1.0

- [`IdempotencyConflict`](/core-concepts/idempotency#recovering-on-conflict) now carries `$e->priorResponse`, the decoded JSON body of the prior successful keyed call for the same key. The catch block can replay it directly instead of re-fetching from upstream or querying `integration_requests` by hand. `null` when nothing recoverable is on file (no prior request row, the prior was logged as failed, `response_data` is null, or the persisted JSON is unparseable). The lookup runs only on the conflict path, with no overhead on the success path. The new constructor argument is added after `$previous`, so existing positional callers (`new IdempotencyConflict($id, $key, $e)`) keep working unchanged.
- New [`Integration::getIdempotencyResponse(string $key): ?array`](/core-concepts/idempotency#recovering-on-conflict) method that backs the exception attribute. Useful when you want to probe for a prior response outside the catch flow, or recover from a key the exception isn't carrying for you. Returns the same shape (`null` when no recoverable prior is on file, the decoded response array otherwise) and scopes to the integration it's called on.

## 4.0.0

Rate limits are now window-aware, and a rate-limited sync item is deferred rather than failed. The GitHub adapter had declared `60` (GitHub's unauthenticated, per-hour figure) in a field the framework read as requests per minute, and when the limiter gave up waiting it threw an exception that failed the `ProcessSyncItem` job and wedged the sync. See the [upgrade guide](/about/upgrade-guide) for the migration.

- Breaking: [`HasScheduledSync::defaultRateLimit()`](/reference/contracts#hasscheduledsync) returns `?Integrations\RateLimit` instead of `?int`. A [`RateLimit`](/core-concepts/rate-limiting) carries the request count, the window in seconds, and a fixed/sliding strategy. Build one with `RateLimit::perHour(5000)`, `RateLimit::perMinute(700)`, `RateLimit::perDay(...)`, or `RateLimit::per($limit, $seconds)`; append `->sliding()` for an upstream that enforces a rolling window. `null` still means unlimited.
- Breaking: `RateLimitExceededException`'s constructor changed. It now carries `retryAfterSeconds` (when capacity is next expected) and an optional `RateLimit`, replacing the old `requestsThisMinute` / `limit` pair.
- The [`RateLimiter`](/core-concepts/rate-limiting#fixed-vs-sliding-windows) enforces a fixed window by default, so a provider may spend its whole budget in a burst, the way a quota like GitHub's hourly limit behaves. Declare the limit `->sliding()` for a rolling window instead. The previous implementation always approximated a sliding minute.
- Inside a sync, hitting the rate limit now [defers the item](/core-concepts/rate-limiting#rate-limits-and-syncs): `ProcessSyncItem` catches `RateLimitExceededException` and releases the job with the limiter's retry-after delay, so the run stays in flight. Previously the exception failed the item and stalled the cursor.
- [`sync.item_tries`](/reference/configuration#sync) now bounds genuine listener exceptions only; transient rate-limit deferrals no longer count against it. New `sync.item_retry_window` config (default 6h) is the absolute bound on how long an item may keep deferring.

## 3.0.0

Sync now tracks per-item completion. Cursor advancement waits for the items' listeners to finish, instead of moving on as soon as the events were dispatched. This closes a silent-data-loss gap: previously a queued listener that exhausted its retries left the item in `failed_jobs` while the cursor had already advanced past it, and once the item fell outside the overlap window it was never re-fetched. See the [upgrade guide](/about/upgrade-guide) for the migration.

- Breaking: `HasScheduledSync::sync()` and `HasIncrementalSync::syncIncremental()` no longer return a `SyncResult`. They now take a [`SyncSession`](/reference/contracts#hasscheduledsync) as a second argument and return `void`. The provider enumerates items and hands each to `$session->dispatch($event, $checkpointValue, $externalId)` instead of dispatching events itself. `HasScheduledSync` also gains `reduceCheckpoints(array): mixed`; implement it directly, or `use Integrations\Concerns\ReducesCheckpointsByMax` for the common "max wins" reduction. Read the previous cursor with `$session->cursor()`; providers no longer write `sync_cursor` themselves.
- Breaking: events handed to `$session->dispatch()` must extend `Integrations\Sync\SyncItemEvent`, and their listeners must not implement `ShouldQueue`. The framework's new `ProcessSyncItem` job is the queued unit, and it invokes listeners synchronously so the job's success reflects the listener's. A queued listener fails the item with `SyncListenerMustNotBeQueuedException`. Listeners that need async follow-up work should dispatch their own job.
- Breaking: `SyncResult` is now `@internal`. The framework constructs it from a run's `integration_sync_items` rows and carries it on the new `SyncCompleted` event; adapters no longer build or return it.
- New `integration_sync_items` table: one row per dispatched item, tracking `pending` / `processing` / `success` / `failed` / `skipped`. Requires running migrations. The sync flow also dispatches a `Bus::batch`, so Laravel's `job_batches` table must exist (`php artisan queue:batches-table`).
- New canonical sync events. [`SyncCompleted`](/reference/events#sync) fires once a run reconciles (carrying a `SyncResult`); [`SyncItemFailed`](/reference/events#sync) fires when an item exhausts its retries. These replace the per-adapter aggregate and failure events.
- New recovery commands. [`integrations:list-failed-items`](/reference/artisan-commands#integrations-list-failed-items) surfaces items needing attention, [`integrations:skip-sync-item`](/reference/artisan-commands#integrations-skip-sync-item) skips an unrecoverable one so the cursor can move on, and [`integrations:advance-cursor`](/reference/artisan-commands#integrations-advance-cursor) re-reconciles a stuck run. `integrations:prune` now also prunes completed sync items ([`pruning.sync_items_days`](/reference/configuration#pruning), default 30).
- New config under [`integrations.sync`](/reference/configuration#sync): `item_queue`, `item_tries`, `item_backoff`, and `max_items_per_batch`.

## 2.5.1

- `RequestExecutor::persistRequest()` now sanitizes non-UTF-8 byte sequences in both `request_data` and `response_data` before insert, replacing them with a `[BINARY <length> bytes sha256=<hash>]` marker. Previously, adapter resources that returned raw bytes (Zendesk `attachments()->download()`, GitHub `assets()->download()`, anything else handing `Http::...->body()` straight through a closure) crashed the INSERT with `SQLSTATE[22007] ... Incorrect string value`, because the columns are `longText` (utf8mb4) and MariaDB/MySQL reject bytes that don't decode as UTF-8. The audit row is still written with the marker for diagnostics. `expires_at` is nulled out on binary responses so the row never becomes a cache source. New `Integrations\Support\BinaryGuard` helper exposes the check. No schema change.

## 2.5.0

- `SyncIntegration::middleware()` now calls `->dontRelease()` on its `WithoutOverlapping` middleware. Previously, when a sibling sync held the lock, the duplicate dispatch was released with `releaseAfter=0` (Laravel's default), which re-popped it instantly and burned through `tries=3` in milliseconds, minting `MaxAttemptsExceededException` events on every overlap even though the actual sync was completing successfully. The schedule cycle (`Schedule::command('integrations:sync')->everyMinute()`) re-dispatches if the integration is still due, so dropping duplicates is information-free. See [Scheduled syncs](/features/scheduled-syncs).
- New [`integrations.sync.job_timeout`](/reference/configuration#sync) config (default 1800s / 30 min). [`integrations:sync`](/reference/artisan-commands#integrations-sync) reads it and passes it to the dispatched `SyncIntegration` job. The job's hardcoded constructor default also bumps from 600s to 1800s so direct dispatchers (tests, custom flows) get the safer default. 10 minutes was tight for first-run backfills against incremental APIs (e.g. multi-page Zendesk ticket windows). With the `dontRelease()` fix above, a long-running sync now holds the lock for the full timeout before the next dispatch can try, so the timeout value matters more than it did under the thundering-herd path.
- [`integrations.sync.lock_ttl`](/reference/configuration#sync) default bumped from 600s to 1800s to match the new `job_timeout`. The lock must outlast the job that holds it; otherwise the lock auto-expires mid-sync and lets a sibling dispatch start running concurrently, which is exactly what `WithoutOverlapping` exists to prevent. If you've published this config and set a custom `lock_ttl`, raise it to at least your `job_timeout`.
- Recommend cursor checkpointing for adapters with long-running incremental syncs. See [Long-running syncs and cursor checkpointing](/features/scheduled-syncs#long-running-syncs-and-cursor-checkpointing) and the adapter-side [Sync pattern](/adapters/building-adapters#sync-pattern). Per item is best when the iterator exposes a per-item callback; per page is a cheap fallback for iterators that only surface page boundaries. Companion adapter-side fix landing in `pocketarc/laravel-integrations-adapters`.

## 2.4.1

- `ResponseHelper::normalize()` now converts `stdClass` payloads to associative arrays before returning them as the parsed value, instead of passing the object through unchanged. Adapters bridging SDKs that call `json_decode($body)` without `assoc=true` (e.g. `Zendesk\API\Http::send()`) flowed `stdClass` trees straight into `Spatie\LaravelData\Data::from()`, where every `Collection<int, T>` element failed validation with "The tickets.0 field must be an array" and the request throws `SchemaDriftException`. Narrowed to `stdClass` so closures returning a typed object (e.g. a Data instance with no `->as()` set) keep current pass-through semantics. Pairs with a matching SDK-boundary fix in `pocketarc/laravel-integrations-adapters`.

## 2.4.0

- [`integration_mappings.external_id`](/reference/database-schema#integration-mappings) widened from 255 to 500 characters. The composite unique index `(integration_id, external_id, internal_type)` still fits under the InnoDB DYNAMIC 3072-byte ceiling, so no index strategy change. Covers real-world cases where adapter-bridged external IDs (e.g. attachment URLs flowing through the GitHub adapter) exceed 255 chars. See [ID mapping](/features/id-mapping#scoping). Existing deployments need a downstream `ALTER` migration since the canonical migration is the only one bumped; fresh installs get the new width on first migrate.
- `IntegrationCredentialCast::set()` now throws `InvalidArgumentException` on anything other than `null`, an array, or a `Spatie\LaravelData\Data` instance, instead of silently returning `null`. Previously, factories that pre-encrypted credentials with `Crypt::encryptString(json_encode(...))` would produce rows with `credentials = NULL` that later tripped credential-type guards in SDK clients on first use. Drop the manual encrypt and pass plain arrays; the cast handles encryption.

## 2.3.0

- [Idempotency](/core-concepts/idempotency) collapses the 2.1 transport-level `withIdempotencyKey($key)` and the 2.2 application-level `withReservation($key, $callback)` into one fluent primitive on the request builder: `$integration->at($endpoint)->withIdempotencyKey($key)->post(...)`. Every keyed call inserts a row in the new `integration_idempotency_keys` table before the closure runs; a second call with the same `(integration_id, key)` throws `Integrations\Exceptions\IdempotencyConflict`. The key is also passed to adapters via `RequestContext` so providers that implement `SupportsIdempotency` (Stripe, etc.) send it on the wire as a defense-in-depth backstop against intra-attempt SDK retries. Breaking changes: `Integration::withReservation()`, `ReservationConflict`, and the `integration_idempotency_reservations` table are removed; replace with `withIdempotencyKey($key)` on the fluent builder, the renamed `IdempotencyConflict` exception, and the `integration_idempotency_keys` table. The auto-UUID form (`withIdempotencyKey()` with no args) is gone, since keys must be application-meaningful and stable across retries; passing `null` is now a no-op rather than triggering UUID generation. The max key length is now 191 characters (was 64 on the transport side). The keyed call refuses to run inside a `DB::transaction()` and throws `RuntimeException` immediately, since an outer rollback would silently nuke the at-most-once row.

## 2.2.0

- Application-level idempotency reservations: `$integration->withReservation($key, $callback)` reserves a `(integration_id, key)` row before running the callback, throws `ReservationConflict` if another caller already reserved that key, and releases the row if the callback throws. Complements the 2.1 transport-level [`withIdempotencyKey()`](/core-concepts/idempotency) for providers that don't natively dedupe (Zendesk, Postmark, etc.). Refuses to run inside a `DB::transaction()` because an outer rollback would also roll back the reservation INSERT and break at-most-once. New `integration_idempotency_reservations` table; `integrations:prune` sweeps rows older than `pruning.reservations_days` (default 90, matching `requests_days`). Superseded by the 2.3.0 collapse, above.

## 2.1.2

- Closure docblock corrected on the fluent builder's terminal verbs (`get()`, `post()`, etc.), on `Integration::request()`, and on `RequestExecutor::execute()`. 2.1.1 used `Closure(RequestContext=): mixed`, which PHPStan reads contravariantly: an optional-arg signature means the wrapper might call the closure with no args, so a closure that requires the arg can't satisfy the type. The new declaration is a union, `(Closure(): mixed)|(Closure(RequestContext): mixed)`, matching what the wrapper actually does (zero-arg or `RequestContext` arg, decided by reflection). Adapters with typed-arg closures now pass `phpstan analyse`. No runtime change.

## 2.1.1

- Attempted PHPDoc fix for typed-arg adapter closures. The declaration shipped in this release (`Closure(RequestContext=): mixed`) is contravariantly wrong; skip to 2.1.2.

## 2.1.0

- [Idempotency keys](/core-concepts/idempotency) as a first-class builder concern: `->withIdempotencyKey($key)` on the fluent builder, with a UUID auto-generated when called with `null`. The key persists to the new `integration_requests.idempotency_key` column and is preserved across inner retry attempts so the upstream sees the same key on every try. New [`SupportsIdempotency`](/reference/contracts#supportsidempotency) marker contract; providers without it get a warning when callers attach a key, since the upstream won't dedupe.
- Provider request IDs captured on `integration_requests.provider_request_id`. Adapters report via `RequestContext::reportResponseMetadata(providerRequestId: ...)` after the SDK call. Stripe captures `Request-Id`; GitHub captures `X-GitHub-Request-Id` plus rate-limit headers. Postmark and Zendesk surface nothing (their SDKs hide response headers).
- [Adaptive rate limiting](/core-concepts/rate-limiting#adaptive-rate-limits): the `RateLimiter` honours `Retry-After` and `X-RateLimit-Remaining: 0` signals when adapters report them, suppressing subsequent requests until the window clears. Falls back to the existing bucket logic when nothing's reported.
- [Circuit breaker](/advanced/circuit-breaker) per-integration. On by default with conservative thresholds (5 consecutive failures, 60s cooldown). Opens on 5xx / connection / `RetryableException` failures; 4xx (except 429) doesn't count. New non-retryable `CircuitOpenException` short-circuits before the rate limiter and retries. Configure under `circuit_breaker.*`.
- `SchemaDriftException` replaces silent `null` returns in the request cache and the live-path Data hydration. When a Spatie Data class fails to hydrate a response (live or cache), the exception is thrown with the parsed payload and target class attached. **Behaviour change**: cached payloads that no longer hydrate now throw on first read instead of degrading invisibly.
- New `RequestContext` argument optionally available to terminal-verb closures (`fn (RequestContext $ctx) => ...`). Gives the closure access to the resolved idempotency key and the metadata-reporting hook. Zero-arg closures continue to work unchanged.

## 2.0.0

- Renamed the request API. The fluent `to()` / `toAs()` pair becomes `at()->as()`, and the standalone `request()` / `requestAs()` methods collapse into one `request()` with an optional `$responseClass` argument.
  - `$integration->to($endpoint)` is now `$integration->at($endpoint)`.
  - `$integration->toAs($endpoint, $class)` is now `$integration->at($endpoint)->as($class)`.
  - `$integration->requestAs($endpoint, $method, $class, $callback, ...)` is now `$integration->request($endpoint, $method, $callback, $class, ...)`, with `$class` optional.
  - `PendingRequest::as(class-string<Data> $class)` is the new chain step for typing responses.

  See [Making requests](/core-concepts/making-requests) for the full builder.

## 1.9.1

- Migration fix: the `integration_mappings` unique index now uses an explicit short name so the generated identifier stays within MySQL's 64-character limit. Previously the auto-generated name caused the migration to fail on MySQL.

## 1.9.0

- [`integrations:install`](/reference/artisan-commands#integrations-install) command: interactive installer that introspects a provider's `credentialDataClass()` / `metadataDataClass()` via reflection, prompts for required fields (masking secret-looking names), validates with the provider's rules, runs the health check if the provider implements `HasHealthCheck`, and upserts the `Integration` row. Non-interactive callers can supply every value via repeatable `--credential=key=value` / `--metadata=key=value` flags. Use `--force` to skip the overwrite and failed-health-check confirmations.

## 1.8.0

- [`registerDefaults()`](/core-concepts/providers#auto-registration-for-companion-packages): companion packages can auto-register their providers so users don't need to edit config after `composer require`. Defaults never override user-defined entries. See [Building adapters](/adapters/building-adapters#auto-registration) for the recommended service provider pattern.

## 1.7.1

- Testing fake: assertion methods now accept the [`METHOD:endpoint`](/testing/testing#filtering-assertions) prefix form in the endpoint argument, matching how `fake()` registers responses. A prefix that conflicts with an explicit `method:` argument raises `InvalidArgumentException` instead of silently mismatching.

## 1.7.0

- [`RetryableException`](/core-concepts/retries#retryableexception): throw to mark an error as retryable, with optional `retryAfterSeconds` and `maxAttempts`. Takes priority over `CustomizesRetry` and default status-code logic. Updated [retry decision chain](/advanced/custom-retry#how-it-composes-with-other-retry-logic).
- [`resultData`](/core-concepts/logging#structured-result-data) parameter on `logOperation()`: nullable JSON column for structured operation output, separate from `metadata`.
- [`OperationStarted`](/reference/events#operations) event: dispatched when an operation is logged with status `processing`.

## 1.6.0

- Added [`upsertByExternalId()`](/features/id-mapping#upsert-by-external-id): resolve, create-or-update, and map in a single atomic call.
- Added [`resolveMappings()`](/features/id-mapping#batch-resolution): batch-resolve multiple external IDs in two queries instead of 2N.
- `resolveMapping()`, `resolveMappings()`, and `upsertByExternalId()` now return properly generic types (`?Ticket` instead of `?Model`).
- Testing fake: [wildcard endpoint matching](/testing/testing#wildcard-endpoints) (`tickets/*.json`), respecting path segment boundaries.
- Testing fake: [method-aware fakes](/testing/testing#method-aware-fakes) (`GET:endpoint` vs `PUT:endpoint`).
- Testing fake: [integration-scoped fakes](/testing/testing#integration-scoped-fakes) via `forIntegration()` fluent API.
- Assertion methods now support optional `method` and `integrationId` [filters](/testing/testing#filtering-assertions).

## 1.5.0

- Automatic detection and honoring of `Retry-After` headers (capped by config, default 10 minutes). 429 falls back to a fixed 30s only when `Retry-After` is absent.
- Integration providers can customize retryability and delay decisions via [`CustomizesRetry`](/advanced/custom-retry).
- New `retry.retry_after_max_seconds` config setting to cap honored `Retry-After` duration (default 600s).

## 1.4.0

- Added a typed request API with typed/untyped flows, typed response reconstruction, a request executor (caching, retries, rate limiting, stale fallback) and a request cache.

## 1.3.0

- Added CI pipeline.
- Added stricter PHPStan rules and safe function wrappers.
- Confirmed PHP 8.2+ support (since Laravel 11/12 require 8.2 at a minimum).

## 1.2.0

- Sync improvements, webhook overhaul, and opinionated defaults.

## 1.1.0

- Added `SyncResult` return type.
- Added per-provider queues.
- Added rate limit backoff.
- Improved health notifications.

## 1.0.0

Initial release.
