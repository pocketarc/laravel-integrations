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

## integrations:list-failed-items

Show sync items that exhausted their retries and need operator attention.

```bash
php artisan integrations:list-failed-items
php artisan integrations:list-failed-items --integration=7 --since="2026-01-01"
```

Prints a table from `integration_sync_items` (id, integration, event, external id, error, attempts, created). A failed item holds the cursor at it until it's resolved: retry the underlying job with `php artisan queue:retry <uuid>`, or skip it (below).

## integrations:skip-sync-item

Mark a permanently-failed sync item as skipped so the cursor can advance past it.

```bash
php artisan integrations:skip-sync-item <id>
```

Only `failed` items can be skipped. The command sets the row to `skipped` and dispatches `FinaliseSyncRun` so the run reconciles and the cursor catches up.

## integrations:advance-cursor

Re-reconcile any sync runs for an integration that are still stuck in `processing`.

```bash
php artisan integrations:advance-cursor <integration>
```

Dispatches `FinaliseSyncRun` for each unreconciled run. `FinaliseSyncRun` bails on its own if a run's items aren't all terminal yet, so this is always safe to run. Useful as a manual nudge if a `finally` callback was lost (e.g. a queue outage).

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

Detailed health report with error rates, response times, and top errors. For providers that implement [`IdentifiesAuthenticatedUser`](/features/authenticated-identity), it also shows the account each integration authenticates as.

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

## integrations:evaluate-failures

Evaluate each active integration's failure rate over a rolling window and emit the [anomaly signal](/advanced/circuit-breaker#anomaly-signal): one [`ElevatedFailureRate`](/reference/events#elevatedfailurerate) per incident, and a [`FailureRateRecovered`](/reference/events#failureraterecovered) when it clears.

```bash
php artisan integrations:evaluate-failures
```

Add to your scheduler:

```php
Schedule::command('integrations:evaluate-failures')->everyFifteenMinutes();
```

The package emits the events; routing them to Sentry, Slack, or elsewhere is the consumer's job. Thresholds are configured under [`observability`](/reference/configuration#observability).

## integrations:prune

Clean up old request, log, idempotency-key, sync-item, and incident records based on configured retention. Also auto-closes stale-open incidents for currently-healthy integrations.

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
    'incidents_days' => 365,
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
