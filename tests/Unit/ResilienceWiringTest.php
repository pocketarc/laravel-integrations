<?php

declare(strict_types=1);

namespace Integrations\Tests\Unit;

use Illuminate\Support\Facades\Cache;
use Integrations\CircuitBreaker;
use Integrations\Enums\HealthStatus;
use Integrations\Exceptions\CircuitOpenException;
use Integrations\IntegrationManager;
use Integrations\Models\Integration;
use Integrations\Tests\Fixtures\TestProvider;
use Integrations\Tests\TestCase;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * End-to-end coverage of the classify-once seam: a single failure should drive
 * both the breaker and health consistently, per its FailureClass.
 */
class ResilienceWiringTest extends TestCase
{
    private Integration $integration;

    protected function setUp(): void
    {
        parent::setUp();

        app(IntegrationManager::class)->register('test', TestProvider::class);
        $this->integration = Integration::create(['provider' => 'test', 'name' => 'Test']);
        $this->integration->refresh();

        config(['integrations.circuit_breaker.strategy' => 'count']);
        config(['integrations.circuit_breaker.threshold' => 2]);
        config(['integrations.rate_limiting.max_wait_seconds' => 0]);
    }

    protected function tearDown(): void
    {
        Cache::flush();
        parent::tearDown();
    }

    public function test_client_error_neither_degrades_health_nor_trips_breaker(): void
    {
        for ($i = 0; $i < 5; $i++) {
            try {
                $this->integration->at('/api/x')
                    ->withAttempts(1)
                    ->get(fn () => throw new HttpException(400, 'bad request'));
            } catch (HttpException) {
                // expected
            }
        }

        $this->integration->refresh();
        $this->assertSame(0, $this->integration->consecutive_failures);
        $this->assertSame(HealthStatus::Healthy, $this->integration->health_status);

        // Breaker stayed closed, and the 400 is persisted on the request row.
        (new CircuitBreaker($this->integration))->enforce();
        $request = $this->integration->requests()->latest('id')->first();
        $this->assertNotNull($request);
        $this->assertSame(400, $request->response_code);
    }

    public function test_upstream_failures_degrade_health_and_trip_breaker(): void
    {
        config(['integrations.health.degraded_after' => 2]);

        for ($i = 0; $i < 2; $i++) {
            try {
                $this->integration->at('/api/x')
                    ->withAttempts(1)
                    ->get(fn () => throw new HttpException(500, 'server error'));
            } catch (HttpException) {
                // expected
            }
        }

        $this->integration->refresh();
        $this->assertSame(2, $this->integration->consecutive_failures);
        $this->assertSame(HealthStatus::Degraded, $this->integration->health_status);

        $this->expectException(CircuitOpenException::class);
        (new CircuitBreaker($this->integration))->enforce();
    }

    public function test_throttle_neither_degrades_health_nor_trips_breaker(): void
    {
        for ($i = 0; $i < 5; $i++) {
            try {
                $this->integration->at('/api/x')
                    ->withAttempts(1)
                    ->get(fn () => throw new HttpException(429, 'too many requests'));
            } catch (HttpException) {
                // expected
            }
        }

        $this->integration->refresh();
        $this->assertSame(0, $this->integration->consecutive_failures);
        $this->assertSame(HealthStatus::Healthy, $this->integration->health_status);
        (new CircuitBreaker($this->integration))->enforce();
        $this->assertTrue(true);
    }
}
