# Scaffolding providers

The `make:integration-provider` Artisan command generates a provider class with the interfaces you need.

## Basic usage

```bash
php artisan make:integration-provider GitHub
```

This creates `app/Integrations/GitHubProvider.php` implementing `IntegrationProvider`.

## Adding capabilities

Use flags to include optional interfaces:

```bash
php artisan make:integration-provider GitHub --sync --webhooks --oauth --health-check
```

| Flag             | Interface added      |
|------------------|----------------------|
| `--sync`         | `HasScheduledSync`   |
| `--webhooks`     | `HandlesWebhooks`    |
| `--oauth`        | `HasOAuth2`          |
| `--health-check` | `HasHealthCheck`     |
| `--all`          | All of the above     |

## Interactive mode

Run without flags for interactive prompts that walk you through which capabilities to include:

```bash
php artisan make:integration-provider GitHub
```

## What gets generated

The generated class includes stub implementations for every method required by the selected interfaces. Fill in the provider-specific logic (API URLs, credential fields, sync operations, etc.) and you're ready to go.

After generating, register the provider in `config/integrations.php`:

```php
'providers' => [
    'github' => App\Integrations\GitHubProvider::class,
],
```
