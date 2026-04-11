# Changelog

All notable changes to this project are documented here. This project follows [Semantic Versioning](https://semver.org/).

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
