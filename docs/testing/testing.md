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

A `createIntegration()` method for test setup. Creates an integration with default values and a registered provider. Use this when your test class already extends a base `TestCase` and you just need a quick integration instance:

```php
use Integrations\Testing\CreatesIntegration;
use Tests\TestCase;

class TicketSyncTest extends TestCase
{
    use CreatesIntegration;

    public function test_syncs_tickets(): void
    {
        $integration = $this->createIntegration('github');

        IntegrationRequest::fake([
            'tickets.list' => ['tickets' => [['id' => 1, 'subject' => 'Bug report']]],
        ]);

        $result = $integration->requestAs(
            endpoint: 'tickets.list',
            method: 'GET',
            responseClass: TicketListResponse::class,
            callback: fn () => Http::get('https://api.github.com/issues'),
        );

        IntegrationRequest::assertRequested('tickets.list');
    }
}
```

The trait handles creating the `Integration` model with sensible defaults (active status, healthy state, a registered provider) so you can focus on the behavior under test.

### IntegrationTestCase

Base test class that extends Laravel's `TestCase` with integration-specific setup and teardown. It activates the fake in `setUp()` and calls `stopFaking()` in `tearDown()`, so you don't need to manage fake lifecycle manually:

```php
use Integrations\Testing\IntegrationTestCase;

class GitHubProviderTest extends IntegrationTestCase
{
    // The fake is automatically activated in setUp()
    // An integration is available via $this->integration

    public function test_fetches_repository(): void
    {
        IntegrationRequest::fake([
            'repos.get' => ['id' => 42, 'name' => 'laravel-integrations'],
        ]);

        $repo = $this->integration->requestAs(
            endpoint: 'repos.get',
            method: 'GET',
            responseClass: RepoData::class,
            callback: fn () => Http::get('https://api.github.com/repos/pocketarc/laravel-integrations'),
        );

        IntegrationRequest::assertRequested('repos.get');
    }

    public function test_handles_api_failure(): void
    {
        IntegrationRequest::fake([
            'repos.get' => new \RuntimeException('API rate limit exceeded'),
        ]);

        $this->expectException(\RuntimeException::class);

        $this->integration->request(
            endpoint: 'repos.get',
            method: 'GET',
            callback: fn () => Http::get('https://api.github.com/repos/pocketarc/laravel-integrations'),
        );
    }
}
```

Use `IntegrationTestCase` when most of your tests need an integration instance and fake -- it removes the boilerplate of setting those up in every test class.
