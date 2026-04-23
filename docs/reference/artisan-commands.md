# Artisan commands

## integrations:install

Interactive installer for a new (or updated) integration. Introspects the provider's `credentialDataClass()` and `metadataDataClass()` to figure out which fields to ask about, validates them against the provider's rules, runs the health check if the provider implements `HasHealthCheck`, and upserts the row in `integrations`.

```bash
php artisan integrations:install {provider} [--name=] [--credential=key=value ...] [--metadata=key=value ...] [--force]
```

### Arguments and options

| Argument / option            | Description                                                                          |
|------------------------------|--------------------------------------------------------------------------------------|
| `provider`                   | The provider key registered in `config/integrations.php` (e.g. `github`).            |
| `--name=`                    | Friendly name for the row. Defaults to the provider's `name()`.                      |
| `--credential=key=value`     | Set a credential field non-interactively. Repeatable.                                |
| `--metadata=key=value`       | Set a metadata field non-interactively. Repeatable.                                  |
| `--force`                    | Skip the overwrite and failed-health-check confirmations.                            |

### Interactive flow

```bash
php artisan integrations:install github
```

The command prompts for every required field declared on the provider's [credential / metadata Data class](/core-concepts/credentials#typed-access-with-data-classes). Optional fields (nullable, or with a default) use their declared default unless you override them with `--credential=name=value` or `--metadata=name=value`. Field names matching `/secret|token|key|password/i` are prompted with masked input.

If an integration with the same `provider` + `name` already exists, the command confirms before overwriting its credentials and metadata. `--force` skips the confirmation.

### Non-interactive flow

Pass every required field through flags and disable prompts:

```bash
php artisan integrations:install github \
    --name="Acme GitHub" \
    --credential=token=ghp_abc123 \
    --metadata=owner=acme \
    --metadata=repo=widgets \
    --no-interaction --force
```

Under `--no-interaction`, any missing required field fails the command before touching the database, so a half-configured row is never written. Malformed flag values (no `=` separator) are warned about and ignored; the subsequent validation pass surfaces the resulting missing fields.

### Health check

If the provider implements [`HasHealthCheck`](/core-concepts/health-monitoring), the command calls `healthCheck()` against the freshly saved row. On a pass, it records the success; on a fail (including thrown exceptions), it asks whether to keep the row for a later retry or roll it back. `--force` keeps the row without prompting.

### Providers without a Data class

If `credentialDataClass()` / `metadataDataClass()` return `null`, the command falls back to the keys in `credentialRules()` / `metadataRules()`. It prompts only for fields whose rule contains the `required` token; others are skipped unless you set them via `--credential` / `--metadata`. Types and defaults come from the Data class when one is present; the rules are the source of truth for validation.

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
