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

## Scoping

Mappings are scoped to the integration, so the same external ID can map to different internal models across integrations. The unique constraint is on `(integration_id, external_id, internal_type)`.

## Upsert behavior

`mapExternalId()` uses `updateOrCreate`, so calling it again with the same external ID and type updates the mapping rather than creating a duplicate.

## Storage

Mappings are stored in the `integration_mappings` table. See [Database Schema](/reference/database-schema) for the full table definition.
