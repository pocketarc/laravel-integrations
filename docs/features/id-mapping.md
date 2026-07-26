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

Calls are serialised per `(integration, model type, external ID)` with a cache lock, so two workers syncing the same upstream record can't each create a row. Only one of those rows could hold the mapping; see [Concurrency](#concurrency).

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

Mappings are scoped to the integration, so the same external ID can map to different internal models across integrations. The unique constraint is on `(integration_id, external_id, internal_type)`. `external_id` is capped at 500 characters; consumers with longer external IDs (e.g. attachment URLs) need a downstream migration to widen further.

## Claiming and re-pointing

`mapExternalId()` claims an external ID for one model. Calling it again with the same model is a no-op. Calling it with a different model throws `MappingAlreadyClaimed`:

```php
$integration->mapExternalId('ticket-4521', $ticketA);
$integration->mapExternalId('ticket-4521', $ticketB); // MappingAlreadyClaimed
```

Use `remapExternalId()` when moving the mapping is what you want:

```php
$integration->remapExternalId('ticket-4521', $ticketB);
```

The model that held the mapping keeps its row and loses its external ID, so reconcile or delete it yourself. `remapExternalId()` won't.

::: warning Changed in 6.0
`mapExternalId()` used to re-point silently. See the [upgrade guide](/about/upgrade-guide) if you relied on that.
:::

## Concurrency

One external ID maps to exactly one local row per `(integration, model type)`. When two workers race to create that row, only one can hold the mapping, and before 6.0 the other was left behind with no mapping at all. It kept every column it had, so it still satisfied ordinary queries, but `findExternalId()` returned null for it and nothing could address it upstream again. One consumer hit this as a ticket that was selected for work, attempted, and failed on every cycle for three days.

Three things guard against it now:

- `upsertByExternalId()` holds a lock scoped to the external ID across the create-and-claim.
- `mapExternalId()` claims through the unique index rather than a read-then-write, so a caller that loses the race is told instead of silently overwriting.
- `upsertByExternalId()` catches that and converges on the winner's row, applying its attributes there.

The lock needs a shared cache driver to do anything. On `array` it is per-process, and the claim collision is what saves you. Tune it with `integrations.mappings.lock_ttl` and `integrations.mappings.lock_wait`.

To find rows that lost a mapping before you upgraded, use [`integrations:find-orphans`](/reference/artisan-commands).

## Collation

`internal_id` is a VARCHAR, because the table is polymorphic and has to hold auto-increment integers, UUIDs and ULIDs alike. On MySQL and MariaDB that means comparing it against your own primary keys fails with `Illegal mix of collations` whenever your tables use a different collation from the one Laravel gave this package's. That comparison is exactly the query you want when hunting rows that lost their mapping.

Set `integrations.mappings.collation` to match your domain tables, then publish and run the migrations:

```php
'mappings' => [
    'collation' => 'utf8mb4_general_ci',
],
```

Ignored on drivers without per-column collation. `integrations:find-orphans` sidesteps the problem entirely by comparing in PHP, so you don't need this just to run it.

## Storage

Mappings are stored in the `integration_mappings` table. See [Database Schema](/reference/database-schema) for the full table definition.
