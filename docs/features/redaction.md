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

To redact a body, the package decodes it, replaces the fields, and encodes it again. PHP decodes a number too large for a float, such as `1e999`, to `INF`, and `json_encode` can't encode `INF`. If the package can't encode the redacted body, it stores an `[UNENCODABLE <exception message>]` marker in place of the whole body. Sensitive values still never reach the database, and the request still succeeds.
