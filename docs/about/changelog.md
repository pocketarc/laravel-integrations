# Changelog

All notable changes to this project are documented here. This project follows [Semantic Versioning](https://semver.org/).

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
