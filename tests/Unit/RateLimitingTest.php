<?php

declare(strict_types=1);

namespace Integrations\Tests\Unit;

use Integrations\Exceptions\RateLimitExceededException;
use Integrations\IntegrationManager;
use Integrations\Models\Integration;
use Integrations\Models\IntegrationRequest;
use Integrations\Tests\Fixtures\TestProvider;
use Integrations\Tests\TestCase;

class RateLimitingTest extends TestCase
{
    private Integration $integration;

    protected function setUp(): void
    {
        parent::setUp();

        $manager = app(IntegrationManager::class);
        $manager->register('test', TestProvider::class);

        $this->integration = Integration::create(['provider' => 'test', 'name' => 'Test']);
        $this->integration->refresh();
    }

    public function test_throws_when_rate_limit_exceeded(): void
    {
        // TestProvider has defaultRateLimit() of 100.
        // Seed 100 requests in the last minute.
        for ($i = 0; $i < 100; $i++) {
            IntegrationRequest::create([
                'integration_id' => $this->integration->id,
                'endpoint' => '/api/bulk',
                'method' => 'GET',
                'created_at' => now()->subSeconds(30),
            ]);
        }

        $this->expectException(RateLimitExceededException::class);

        $this->integration->request(
            endpoint: '/api/next',
            method: 'GET',
            callback: fn () => ['ok' => true],
        );
    }

    public function test_allows_requests_under_limit(): void
    {
        IntegrationRequest::create([
            'integration_id' => $this->integration->id,
            'endpoint' => '/api/first',
            'method' => 'GET',
            'created_at' => now()->subSeconds(30),
        ]);

        $result = $this->integration->request(
            endpoint: '/api/second',
            method: 'GET',
            callback: fn () => ['ok' => true],
        );

        $this->assertSame(['ok' => true], $result);
    }

    public function test_rate_limiting_can_be_disabled(): void
    {
        config(['integrations.rate_limiting.enabled' => false]);

        for ($i = 0; $i < 100; $i++) {
            IntegrationRequest::create([
                'integration_id' => $this->integration->id,
                'endpoint' => '/api/bulk',
                'method' => 'GET',
                'created_at' => now()->subSeconds(30),
            ]);
        }

        $result = $this->integration->request(
            endpoint: '/api/next',
            method: 'GET',
            callback: fn () => ['ok' => true],
        );

        $this->assertSame(['ok' => true], $result);
    }
}
