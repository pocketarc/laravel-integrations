# Zendesk Adapter

Wraps the [zendesk/zendesk_api_client_php](https://github.com/zendesk/zendesk_api_client_php) SDK. Focused on tickets, users, and comments.

Part of the [`pocketarc/laravel-integrations-adapters`](https://github.com/pocketarc/laravel-integrations-adapters) package.

## Installation

```bash
composer require pocketarc/laravel-integrations-adapters
```

## Setup

```php
// config/integrations.php
'providers' => [
    'zendesk' => \Integrations\Adapters\Zendesk\ZendeskProvider::class,
],
```

```php
$integration = Integration::create([
    'provider' => 'zendesk',
    'name' => 'Production Zendesk',
    'credentials' => ['email' => 'admin@acme.com', 'token' => 'your-api-token'],
    'metadata' => ['subdomain' => 'acme'],
]);
```

| Credentials | Metadata |
|-------------|----------|
| `email` (string) - Zendesk admin email | `subdomain` (string) - Zendesk subdomain |
| `token` (string) - API token | `custom_domain` (?string) - full base URL including scheme, e.g. `https://support.acme.com` |

The health check appends `/api/v2/users/me.json` to `custom_domain` if set, otherwise uses `https://{subdomain}.zendesk.com`.

## Resources

```php
$client = new ZendeskClient($integration);
```

| Resource | Method | Description |
|----------|--------|-------------|
| `$client->tickets()` | `->get($ticketId)` | Get a single ticket. Returns `?ZendeskTicketData`. |
| | `->list($callback)` | Iterate all tickets via the SDK iterator. |
| | `->since($since, $callback)` | Incremental ticket export with sideloaded users. Callback receives `ZendeskTicketData` and `?ZendeskUserData`. |
| | `->newerThan($minId, $callback)` | Fetch tickets with ID > `$minId` via Search API. For catching missed items. |
| | `->close($ticketId)` | Set ticket status to "solved". Returns `?ZendeskTicketData`. |
| | `->reopen($ticketId)` | Set ticket status to "open". Returns `?ZendeskTicketData`. |
| `$client->comments()` | `->list($ticketId, $callback)` | Iterate comments on a ticket (cursor-paginated). Callback receives `ZendeskCommentData`. |
| | `->newerThan($minId, $callback, $days)` | Find comments with ID > `$minId` across tickets updated in the last `$days` (default 7). For catching missed comments. Callback receives `ZendeskCommentData` and ticket ID. |
| | `->add($ticketId, $body)` | Add a public comment. Returns `?ZendeskCommentData`. |
| | `->addInternalNote($ticketId, $body)` | Add an internal note (not visible to requester). Returns `?ZendeskCommentData`. |
| `$client->users()` | `->get($userId)` | Get a single user. Returns `?ZendeskUserData`. |
| | `->list($callback?)` | Iterate all users. Returns `Collection<ZendeskUserData>`. |
| `$client->attachments()` | `->download($url)` | Download an attachment by content URL. |
| | `->freshUrl($ticketId, $attachmentId)` | Get a fresh (non-expired) content URL for an attachment. |

All resource methods go through `Integration::request()` / `requestAs()` internally, so every API call is logged, health-tracked, and rate-limited. Retry is handled by the core with method-aware defaults (GET = 3 attempts, non-GET = 1). The Zendesk SDK wraps Guzzle exceptions, which the core detects via exception chain walking and respects `Retry-After` headers automatically.

## Sync

The adapter syncs tickets via the Zendesk Incremental Tickets API (`$client->tickets()->since()`). Each ticket dispatches a `ZendeskTicketSynced` event with both the ticket data and the requester's user data (sideloaded). Failed items dispatch `ZendeskTicketSyncFailed` and don't advance the sync cursor past them. After the sync completes, `ZendeskSyncCompleted` fires with the `SyncResult`.

First sync (null cursor) fetches all tickets from timestamp 0. Set `sync_cursor` on the integration to control the starting point:

```php
$integration->updateSyncCursor('2024-05-01T00:00:00+00:00');
```

Every sync (including the first one with a seeded cursor) subtracts a 1-hour buffer from the cursor. This buffer catches items updated between syncs. Consumers should use [`upsertByExternalId()`](/features/id-mapping#upsert-by-external-id) in their event listeners since overlap is expected.

Defaults: 5-minute sync interval, 100 requests/minute rate limit.

## Provider request IDs and idempotency

Zendesk emits an `X-Zendesk-Request-Id` header that's useful when filing support tickets, but the Zendesk PHP SDK doesn't expose response headers to callers. `integration_requests.provider_request_id` stays `null` for Zendesk calls until the SDK gains accessor support or we fork it.

Zendesk doesn't natively dedupe by idempotency key. `ZendeskProvider` does **not** implement `SupportsIdempotency`; the warning in [Idempotency](/core-concepts/idempotency) applies if a caller attaches one.

## Data classes

| Class | Description |
|-------|-------------|
| `ZendeskTicketData` | Ticket with status, requester, assignee, custom fields, satisfaction rating, tags. Stores original API response. |
| `ZendeskCommentData` | Comment with body (plain/HTML), attachments, via channel. Has `hasAttachments()` and `getImageAttachments()` helpers. Stores original API response. |
| `ZendeskUserData` | User with role, org, locale, timezone, phone, photo. Handles email fallback for users without emails via `prepareForPipeline()`. Stores original API response. |
| `ZendeskAttachmentData` | Attachment with file name, content type, size, dimensions, malware scan result, thumbnails. |
| `ZendeskCustomFieldData` | Custom field ID + value pair. |
| `ZendeskViaData` | Channel and source info (how the ticket/comment was created). Normalizes integer channel values to strings via `prepareForPipeline()`. |
| `ZendeskSatisfactionRatingData` | Satisfaction survey score. |
| `ZendeskPhotoData` | User profile photo with thumbnails. |
| `ZendeskThumbnailData` | Thumbnail image for attachments/photos. |
| `ZendeskIncrementalTicketResponse` | Typed response for the incremental tickets API. Contains `tickets`, `users`, `next_page`, `count`. Has `nextTimestamp()` for pagination. |
| `ZendeskSearchResponse` | Typed response for the search API. Contains `results` (tickets), `users`, `next_page`. |
| `ZendeskCommentPageResponse` | Typed response for the comments endpoint. Contains `comments` and `meta` (pagination). |
| `ZendeskPaginationMeta` | Cursor pagination metadata with `has_more` and `after_cursor`. |

## Enums

| Enum | Values |
|------|--------|
| `ZendeskStatus` | `New`, `Open`, `Pending`, `Hold`, `Solved`, `Closed`, `Deleted`. Has `isResolved()`, `isDeleted()`, `closedStatuses()`, `openStatuses()` helpers. |
