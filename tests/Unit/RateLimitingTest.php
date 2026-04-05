<?php

declare(strict_types=1);

namespace Integrations\Tests\Unit;

use Illuminate\Support\Facades\Cache;
use Integrations\Exceptions\RateLimitExceededException;
use Integrations\IntegrationManager;
use Integrations\Models\Integration;
use Integrations\Tests\Fixtures\TestOkResponse;
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
        // Populate cache bucket so the rate limiter sees 100 requests this minute.
        $key = 'integrations:rate:'.$this->integration->id.':'.now()->format('Y-m-d-H-i');
        Cache::put($key, 100, 120);
        config(['integrations.rate_limiting.max_wait_seconds' => 0]);

        $this->expectException(RateLimitExceededException::class);

        $this->integration->requestAs(
            endpoint: '/api/next',
            method: 'GET',
            responseClass: TestOkResponse::class,
            callback: fn () => ['ok' => true],
        );
    }

    public function test_allows_requests_under_limit(): void
    {
        $result = $this->integration->requestAs(
            endpoint: '/api/second',
            method: 'GET',
            responseClass: TestOkResponse::class,
            callback: fn () => ['ok' => true],
        );

        $this->assertInstanceOf(TestOkResponse::class, $result);
        $this->assertTrue($result->ok);
    }
}
