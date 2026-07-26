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

Calls are serialised per `(integration, model type, external ID)` with a cache lock, so two workers syncing the same upstream record don't each create a row. See [Concurrency](#concurrency) for what happens when the cache driver isn't shared.

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

When syncing many items, `resolveMapping()` does one query per call. `resolveMappings()` resolves a whole batch in one query for the mappings and one for the models. It splits batches over 500 IDs into further chunks, to stay inside the driver's bind-parameter limit:

```php
$tickets = $integration->resolveMappings(
    externalIds: ['123', '456', '789'],
    internalType: Ticket::class,
);

// Returns Collection<string, Ticket|null> keyed by external ID
$ticket123 = $tickets->get('123'); // Ticket instance or null
```

## Scoping

Mappings are scoped to the integration and the model type: the unique constraint is on `(integration_id, external_id, internal_type)`. The same external ID can therefore map to one `Ticket` and one `Contact`, and to a different pair on another integration. `external_id` is capped at 500 characters; consumers with longer external IDs (e.g. attachment URLs) need a downstream migration to widen further.

## Claiming and re-pointing

`mapExternalId()` claims an external ID for one model, within the `(integration, model type)` scope above. Calling it again with the same model is a no-op. Calling it with a different model of that type throws `MappingAlreadyClaimed`:

```php
$integration->mapExternalId('ticket-4521', $ticketA);
$integration->mapExternalId('ticket-4521', $ticketB); // MappingAlreadyClaimed
```

Use `remapExternalId()` when moving the mapping is what you want:

```php
$integration->remapExternalId('ticket-4521', $ticketB);
```

The model that held the mapping keeps its row and loses its external ID, so reconcile or delete it yourself.

::: warning Changed in 6.0
`mapExternalId()` used to re-point silently. See the [upgrade guide](/about/upgrade-guide) if you relied on that.
:::

## Concurrency

One external ID maps to exactly one local row per `(integration, model type)`. When two workers race to create that row, only one can hold the mapping, and before 6.0 the other was left behind with no mapping at all: intact in every column, still returned by ordinary queries, but null from `findExternalId()` and unaddressable upstream. The [upgrade guide](/about/upgrade-guide) has what that cost one consumer.

Three things guard against it now:

- `upsertByExternalId()` holds a lock scoped to the external ID across the create-and-claim.
- `mapExternalId()` claims through the unique index rather than a read-then-write, so a caller that loses the race gets an exception instead of silently overwriting the mapping.
- `upsertByExternalId()` catches that and converges on the winner's row, applying its attributes there.

The lock only serialises across processes on a shared cache driver. On `array` it is per-process, so you fall back to the claim collision. Tune it with `integrations.mappings.lock_ttl` and `integrations.mappings.lock_wait`.

To find rows that lost a mapping before you upgraded, use [`integrations:find-orphans`](/reference/artisan-commands).

## Collation

`internal_id` is a VARCHAR, because the table is polymorphic and has to hold auto-increment integers, UUIDs and ULIDs alike. On MySQL and MariaDB that means comparing it against your own primary keys fails with `Illegal mix of collations` whenever your tables use a different collation from the one Laravel gave this package's. That comparison is exactly the query you want when hunting rows that lost their mapping.

Set `integrations.mappings.collation` to match your domain tables, then publish and run the migrations:

```php
'mappings' => [
    'collation' => 'utf8mb4_general_ci',
],
```

The setting is ignored on drivers without per-column collation. `integrations:find-orphans` compares keys in PHP rather than joining, so you don't need this just to run it.

## Storage

Mappings are stored in the `integration_mappings` table. See [Database Schema](/reference/database-schema) for the full table definition.
