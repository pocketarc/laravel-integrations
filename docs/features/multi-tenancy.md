# Multi-tenancy

The `Integration` model has optional polymorphic `owner_type`/`owner_id` columns for multi-tenant setups.

## Assigning ownership

```php
$integration = Integration::create([
    'provider' => 'github',
    'name' => 'Acme GitHub',
    'credentials' => [...],
    'owner_type' => Team::class,
    'owner_id' => $team->id,
]);
```

## Querying by owner

```php
Integration::ownedBy($team)->get();
```

## Accessing the owner

```php
$integration->owner; // returns the Team model
```

## When to skip

If you don't need multi-tenancy, leave `owner_type` and `owner_id` as null. The package works fine without them.
