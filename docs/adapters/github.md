# GitHub Adapter

Wraps the [knplabs/github-api](https://github.com/KnpLabs/php-github-api) SDK. Currently focused on issues (not PRs, releases, etc.).

Part of the [`pocketarc/laravel-integrations-adapters`](https://github.com/pocketarc/laravel-integrations-adapters) package.

## Installation

```bash
composer require pocketarc/laravel-integrations-adapters
```

## Setup

```php
// config/integrations.php
'providers' => [
    'github' => \Integrations\Adapters\GitHub\GitHubProvider::class,
],
```

```php
$integration = Integration::create([
    'provider' => 'github',
    'name' => 'My Repo',
    'credentials' => ['token' => 'ghp_...'],
    'metadata' => ['owner' => 'acme', 'repo' => 'widgets'],
]);
```

| Credentials | Metadata |
|-------------|----------|
| `token` (string) - GitHub personal access token | `owner` (string) - repository owner |
| | `repo` (string) - repository name |

## Resources

```php
$client = new GitHubClient($integration);
```

| Resource | Method | Description |
|----------|--------|-------------|
| `$client->issues()` | `->create($title, $body, $labels, $idempotencyKey?)` | Create an issue. Returns `GitHubIssueData`. |
| | `->get($number)` | Get a single issue by number. |
| | `->since($since, $callback)` | Iterate issues updated since a timestamp. Skips PRs. |
| | `->close($number, $stateReason, $idempotencyKey?)` | Close an issue. Optional state reason (completed, not_planned, duplicate). |
| | `->reopen($number, $idempotencyKey?)` | Reopen a closed issue. |
| | `->timeline($number, $callback)` | Iterate timeline events (labels, assignments, etc.). |
| `$client->comments()` | `->list($number, $callback)` | Iterate all comments on an issue. |
| | `->add($number, $body, $idempotencyKey?)` | Add a comment to an issue. Returns `?GitHubCommentData`. |
| `$client->assets()` | `->download($url)` | Download an asset with token auth for GitHub-hosted URLs. |

All methods go through `Integration::request()` / `requestAs()` internally, so every API call is logged, health-tracked, and rate-limited. The provider implements `CustomizesRetry` so the core handles retry for GitHub SDK exceptions (rate limits, server errors, connection failures) with method-aware defaults (GET = 3 attempts, non-GET = 1).

## Sync

The adapter syncs issues via `$client->issues()->since()`. Each issue dispatches a `GitHubIssueSynced` event. Failed items dispatch `GitHubIssueSyncFailed` and don't advance the sync cursor past them. After the sync completes, `GitHubSyncCompleted` fires with the `SyncResult`.

First sync (null cursor) fetches all issues from timestamp 0. Set `sync_cursor` on the integration to control the starting point:

```php
$integration->updateSyncCursor('2024-05-01T00:00:00+00:00');
```

Every sync (including the first one with a seeded cursor) subtracts a 1-hour buffer from the cursor. This buffer catches items updated between syncs. Consumers should use [`upsertByExternalId()`](/features/id-mapping#upsert-by-external-id) in their event listeners since overlap is expected.

Defaults: 5-minute sync interval, 60 requests/minute rate limit. The adapter also feeds the [adaptive rate limiter](/core-concepts/rate-limiting#adaptive-rate-limits) from GitHub's `X-RateLimit-Remaining` / `X-RateLimit-Reset` headers, so when a token's hourly bucket runs out, subsequent requests are suppressed until the reset window passes.

## Provider request IDs

GitHub's `X-GitHub-Request-Id` is captured on `integration_requests.provider_request_id` for every call. Useful when filing issues against the SDK or [GitHub's own bug tracker](https://github.com/orgs/community/discussions). Asset downloads (raw `Http::` calls outside the SDK) capture the same header.

## Idempotency

All write methods (`issues()->create`, `close`, `reopen`, `comments()->add`) accept an optional `$idempotencyKey`. Pass a stable, application-meaningful value (e.g. `"open-issue:order-{$order->id}"`, `"close-issue:{$issueNumber}"`) when you need at-most-once execution: the package writes a row in `integration_idempotency_keys` before the call fires, throws `Integrations\Exceptions\IdempotencyConflict` on a second call with the same key, and lets you skip the work. GitHub itself doesn't natively dedupe by header (`GitHubProvider` doesn't implement `SupportsIdempotency`), so the local row is the only protection here. Pass `null` (the default) to skip idempotency entirely. See [Idempotency](/core-concepts/idempotency) for the full picture.

## Data classes

| Class | Description |
|-------|-------------|
| `GitHubIssueData` | Issue with body, state, user, labels, assignees, attachments (extracted from body HTML via `prepareForPipeline()`). Stores original API response. |
| `GitHubCommentData` | Comment with body, user, attachments (extracted from body HTML via `prepareForPipeline()`). Stores original API response. |
| `GitHubEventData` | Timeline event (label, assignment, close, etc.) with formatted descriptions. Handles cross-reference ID synthesis via `prepareForPipeline()`. |
| `GitHubUserData` | User with login, avatar, name, email. |
| `GitHubAttachmentData` | Attachment URL extracted from issue/comment HTML body. |

## Enums

| Enum | Values |
|------|--------|
| `GitHubEventType` | ~50 timeline event types with human-readable descriptions. |
| `GitHubIssueStateReason` | `Completed`, `NotPlanned`, `Duplicate`, `Reopened` |
