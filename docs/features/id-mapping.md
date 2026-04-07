# ID mapping

Track the relationship between external provider IDs and your internal models.

## Basic usage

```php
// Map an external ID to an internal model
$integration->mapExternalId('ticket-4521', $ticket);

// Resolve: external ID -> internal model
$ticket = $integration->resolveMapping('ticket-4521', Ticket::class);

// Reverse: internal model -> external ID
$externalId = $integration->findExternalId($ticket);
```

Both `resolveMapping()` and `findExternalId()` return properly typed results -- `resolveMapping('id', Ticket::class)` returns `?Ticket`, not `?Model`.

## Upsert by external ID

The most common sync pattern is: look up a local record by its external ID, create or update it, and register the mapping. `upsertByExternalId()` does this in a single call:

```php
$ticket = $integration->upsertByExternalId(
    externalId: (string) $issue->number,
    modelClass: Ticket::class,
    attributes: ['title' => $issue->title, 'status' => $issue->state],
);
```

The method resolves the mapping, updates the existing model if found, or creates a new model and registers the mapping if not. The create + map step is wrapped in a database transaction for atomicity.

This replaces the manual pattern:

```php
$existing = $integration->resolveMapping($externalId, Ticket::class);
if ($existing) {
    $existing->update($attributes);
    $ticket = $existing;
} else {
    $ticket = Ticket::create($attributes);
    $integration->mapExternalId($externalId, $ticket);
}
```

## Batch resolution

When syncing many items, `resolveMapping()` does one query per call. Use `resolveMappings()` to resolve multiple external IDs in two queries (one for mappings, one for models):

```php
$tickets = $integration->resolveMappings(
    externalIds: ['123', '456', '789'],
    internalType: Ticket::class,
);

// Returns Collection<string, Ticket|null> keyed by external ID
$ticket123 = $tickets->get('123'); // Ticket instance or null
```

## Scoping

Mappings are scoped to the integration, so the same external ID can map to different internal models across integrations. The unique constraint is on `(integration_id, external_id, internal_type)`.

## Upsert behavior

`mapExternalId()` uses `updateOrCreate`, so calling it again with the same external ID and type updates the mapping rather than creating a duplicate.

## Storage

Mappings are stored in the `integration_mappings` table. See [Database Schema](/reference/database-schema) for the full table definition.
