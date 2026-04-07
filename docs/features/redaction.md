# Data redaction

Providers handling sensitive data can declare fields to redact before persistence by implementing the `RedactsRequestData` interface.

## The RedactsRequestData interface

```php
use Integrations\Contracts\RedactsRequestData;

interface RedactsRequestData
{
    public function sensitiveRequestFields(): array;
    public function sensitiveResponseFields(): array;
}
```

## Example

```php
class StripeProvider implements IntegrationProvider, RedactsRequestData
{
    public function sensitiveRequestFields(): array
    {
        return ['card.number', 'card.cvc', 'password'];
    }

    public function sensitiveResponseFields(): array
    {
        return ['token', 'secret_key'];
    }
}
```

Fields use dot-notation for nested data and are replaced with `[REDACTED]` in stored request and response data. Redaction happens before persistence, so sensitive values never reach the database.
