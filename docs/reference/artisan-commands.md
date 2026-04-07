# Artisan commands

## integrations:sync

Find overdue integrations and dispatch sync jobs.

```bash
php artisan integrations:sync
```

Finds all active integrations where `next_sync_at` has passed and dispatches a `SyncIntegration` job for each. Add to your scheduler:

```php
Schedule::command('integrations:sync')->everyMinute();
```

## integrations:list

Show all integrations with health, last sync, and request counts.

```bash
php artisan integrations:list
```

Example output:

```
+----------+----------+---------+---------------------+----------+-----------+
| Name     | Provider | Health  | Last Synced          | Requests | Error Rate|
+----------+----------+---------+---------------------+----------+-----------+
| Prod ZD  | zendesk  | healthy | 2026-03-22 10:15:00 | 1,243    | 0.8%      |
| GitHub   | github   | degraded| 2026-03-22 10:10:00 | 891      | 12.3%     |
+----------+----------+---------+---------------------+----------+-----------+
```

## integrations:health

Detailed health report with error rates, response times, and top errors.

```bash
php artisan integrations:health
```

## integrations:test

Run `HasHealthCheck` on all supporting integrations.

```bash
php artisan integrations:test
```

## integrations:stats

Show request counts, error rates, and cache hit ratios per integration.

```bash
php artisan integrations:stats
```

## integrations:prune

Clean up old request and log records based on configured retention.

```bash
php artisan integrations:prune
```

Add to your scheduler:

```php
Schedule::command('integrations:prune')->daily();
```

Configure retention in `config/integrations.php`:

```php
'pruning' => [
    'requests_days' => 90,
    'logs_days' => 365,
    'chunk_size' => 1000,
],
```

## integrations:recover-webhooks

Reset stale processing webhooks to pending and re-dispatch them.

```bash
php artisan integrations:recover-webhooks
```

Add to your scheduler:

```php
Schedule::command('integrations:recover-webhooks')->hourly();
```

A webhook is considered stale after `webhook.processing_timeout` seconds (default 30 minutes).

## integrations:replay-webhook

Re-dispatch a stored webhook payload.

```bash
php artisan integrations:replay-webhook {webhookId}
```

Reconstructs the request from stored data and re-dispatches it through `handleWebhook()`.

## make:integration-provider

Scaffold a new provider class. See [Scaffolding Providers](/getting-started/scaffolding).

```bash
php artisan make:integration-provider {name} [--sync] [--webhooks] [--oauth] [--health-check] [--all]
```
