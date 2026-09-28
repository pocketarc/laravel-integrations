<?php

declare(strict_types=1);

namespace Integrations\Tests\Unit;

use Integrations\IntegrationManager;
use Integrations\Models\Integration;
use Integrations\Models\IntegrationRequest;
use Integrations\Tests\Fixtures\TestDataResponse;
use Integrations\Tests\Fixtures\TestProvider;
use Integrations\Tests\TestCase;
use RuntimeException;

class CachingTest extends TestCase
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

    public function test_cache_hit_returns_cached_response(): void
    {
        $callCount = 0;

        $this->integration->request(
            endpoint: '/api/data',
            method: 'GET',
            responseClass: TestDataResponse::class,
            callback: function () use (&$callCount) {
                $callCount++;

                return ['data' => 'fresh'];
            },
            cacheFor: now()->addHour(),
        );

        $result = $this->integration->request(
            endpoint: '/api/data',
            method: 'GET',
            responseClass: TestDataResponse::class,
            callback: function () use (&$callCount) {
                $callCount++;

                return ['data' => 'should not be called'];
            },
            cacheFor: now()->addHour(),
        );

        $this->assertSame(1, $callCount);
        $this->assertInstanceOf(TestDataResponse::class, $result);
        $this->assertSame('fresh', $result->data);

        $cached = IntegrationRequest::first();
        $this->assertNotNull($cached);
        $this->assertSame(1, $cached->cache_hits);
    }

    public function test_stale_cache_returned_on_failure(): void
    {
        $this->integration->request(
            endpoint: '/api/flaky',
            method: 'GET',
            responseClass: TestDataResponse::class,
            callback: fn () => ['data' => 'original'],
            cacheFor: now()->subSecond(), // expired
        );

        $result = $this->integration->request(
            endpoint: '/api/flaky',
            method: 'GET',
            responseClass: TestDataResponse::class,
            callback: fn () => throw new RuntimeException('Service down'),
            serveStale: true,
        );

        $this->assertInstanceOf(TestDataResponse::class, $result);
        $this->assertSame('original', $result->data);

        $original = IntegrationRequest::first();
        $this->assertNotNull($original);
        $this->assertSame(1, $original->stale_hits);
    }

    public function test_no_stale_fallback_when_disabled(): void
    {
        $this->integration->request(
            endpoint: '/api/flaky',
            method: 'GET',
            responseClass: TestDataResponse::class,
            callback: fn () => ['data' => 'original'],
            cacheFor: now()->subSecond(),
        );

        $this->expectException(RuntimeException::class);

        $this->integration->request(
            endpoint: '/api/flaky',
            method: 'GET',
            responseClass: TestDataResponse::class,
            callback: fn () => throw new RuntimeException('Service down'),
            serveStale: false,
        );
    }

    public function test_request_bodies_that_share_a_long_prefix_do_not_share_a_cached_response(): void
    {
        $prefix = str_repeat('a', 65530);

        $first = $this->integration->request(
            endpoint: '/api/search',
            method: 'POST',
            callback: fn (): array => ['query' => 'first'],
            requestData: $prefix.'first',
            cacheFor: now()->addHour(),
        );
        $second = $this->integration->request(
            endpoint: '/api/search',
            method: 'POST',
            callback: fn (): array => ['query' => 'second'],
            requestData: $prefix.'second',
            cacheFor: now()->addHour(),
        );

        $this->assertSame(['query' => 'first'], $first);
        $this->assertSame(['query' => 'second'], $second);
    }

    public function test_a_row_hashed_from_a_truncated_body_is_not_served(): void
    {
        $prefix = str_repeat('a', 65530);

        $this->integration->requests()->create([
            'endpoint' => '/api/search',
            'method' => 'POST',
            'request_data' => $prefix,
            'request_data_hash' => hash('xxh128', $prefix),
            'response_data' => '{"query":"longer body"}',
            'response_success' => true,
            'expires_at' => now()->addHour(),
        ]);

        $result = $this->integration->request(
            endpoint: '/api/search',
            method: 'POST',
            callback: fn (): array => ['query' => 'this body'],
            requestData: $prefix,
            cacheFor: now()->addHour(),
        );

        $this->assertSame(['query' => 'this body'], $result);
    }
}
