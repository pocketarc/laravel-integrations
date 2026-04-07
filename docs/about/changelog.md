# Changelog

All notable changes to this project are documented here. This project follows [Semantic Versioning](https://semver.org/).

## 1.5.0

- Automatic detection and honoring of `Retry-After` headers (capped by config, default 10 minutes). 429 falls back to a fixed 30s only when `Retry-After` is absent.
- Integration providers can optionally customize retryability and delay decisions via [`CustomizesRetry`](/advanced/custom-retry), while falling back to core behavior when not specified.
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
