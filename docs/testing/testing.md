# Testing

A testing fake follows the `Http::fake()` pattern, with no real API calls and no database writes.

## Activating the fake

```php
use Integrations\Models\IntegrationRequest;

IntegrationRequest::fake([
    '/api/v2/tickets.json' => ['tickets' => [['id' => 1, 'subject' => 'Test']]],
    'customers.create' => fn () => ['id' => 'cus_123', 'email' => 'test@example.com'],
]);
```

When the fake is active, both `request()` and `requestAs()` skip rate limiting, caching, health tracking, and database persistence entirely. They record requests in memory and return your fake responses (or `null` for unmatched endpoints).

## Making assertions

```php
IntegrationRequest::assertRequested('/api/v2/tickets.json');
IntegrationRequest::assertRequested('/api/v2/tickets.json', times: 2);
IntegrationRequest::assertNotRequested('customers.delete');
IntegrationRequest::assertRequestedWith('customers.create', function (string $requestData) {
    return str_contains($requestData, 'test@example.com');
});
IntegrationRequest::assertRequestCount(5);
IntegrationRequest::assertNothingRequested();
```

## Sequences and exceptions

```php
use Integrations\Testing\ResponseSequence;

IntegrationRequest::fake([
    '/api/items' => new ResponseSequence('first', 'second', 'third'),
    '/api/fail' => new \RuntimeException('Service unavailable'),
]);

// Returns 'first', 'second', 'third', then null
$r1 = $integration->request(endpoint: '/api/items', method: 'GET', callback: fn () => Http::get($url));

// Throws RuntimeException
$integration->request(endpoint: '/api/fail', method: 'GET', callback: fn () => Http::get($url));
```

## Cleanup

```php
IntegrationRequest::stopFaking();
```

## Test helpers

### CreatesIntegration trait

A `createIntegration()` method for test setup. Creates an integration with default values and a registered provider.

### IntegrationTestCase

Base test class that extends Laravel's `TestCase` with integration-specific setup and teardown.
