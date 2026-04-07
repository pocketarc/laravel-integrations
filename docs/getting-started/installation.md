# Installation

## Install the package

```bash
composer require pocketarc/laravel-integrations
```

## Publish config and migrations

```bash
php artisan vendor:publish --tag=integrations-config
php artisan vendor:publish --tag=integrations-migrations
php artisan migrate
```

This creates five database tables (with a configurable prefix, defaulting to `integration`):

| Table                      | Purpose                              |
|----------------------------|--------------------------------------|
| `integrations`             | Integration records with credentials |
| `integration_requests`     | API request/response log             |
| `integration_logs`         | Operation-level logs (syncs, etc.)   |
| `integration_mappings`     | External ID to internal model map    |
| `integration_webhooks`     | Webhook audit trail                  |

## Optional: publish notification stubs

If you want to customize health notifications:

```bash
php artisan vendor:publish --tag=integrations-notifications
```

## Scheduler setup

If you plan to use scheduled syncs or webhook recovery, add these to your scheduler:

```php
// bootstrap/app.php (Laravel 11+)
Schedule::command('integrations:sync')->everyMinute();
Schedule::command('integrations:recover-webhooks')->hourly();
Schedule::command('integrations:prune')->daily();
```
