<?php

declare(strict_types=1);

namespace Integrations\Tests\Unit;

use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Integrations\CircuitBreaker;
use Integrations\Enums\FailureClass;
use Integrations\Exceptions\CircuitOpenException;
use Integrations\IntegrationManager;
use Integrations\Models\Integration;
use Integrations\Support\CircuitBreakerRateStrategy;
use Integrations\Tests\Fixtures\TestProvider;
use Integrations\Tests\TestCase;

class CircuitBreakerRateStrategyTest extends TestCase
{
    private Integration $integration;

    protected function setUp(): void
    {
        parent::setUp();

        app(IntegrationManager::class)->register('test', TestProvider::class);
        $this->integration = Integration::create(['provider' => 'test', 'name' => 'Test']);
        $this->integration->refresh();

        config([
            'integrations.circuit_breaker.strategy' => 'rate',
            'integrations.circuit_breaker.time_window' => 60,
            'integrations.circuit_breaker.failure_rate_threshold' => 50,
            'integrations.circuit_breaker.minimum_requests' => 10,
            'integrations.circuit_breaker.cooldown_seconds' => 30,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        Cache::flush();
        parent::tearDown();
    }

    public function test_below_minimum_requests_does_not_trip(): void
    {
        $breaker = new CircuitBreaker($this->integration);

        // 9 upstream failures: 100% failure rate, but below the floor of 10.
        // This is the documented low-volume limitation, asserted on purpose.
        for ($i = 0; $i < 9; $i++) {
            $breaker->recordFailure(FailureClass::Upstream);
        }

        $breaker->enforce();
        $this->assertTrue(true);
    }

    public function test_trips_when_failure_rate_crosses_threshold(): void
    {
        $breaker = new CircuitBreaker($this->integration);

        // 10 outcomes, all failures: 100% >= 50%, floor met.
        for ($i = 0; $i < 10; $i++) {
            $breaker->recordFailure(FailureClass::Upstream);
        }

        $this->expectException(CircuitOpenException::class);
        $breaker->enforce();
    }

    public function test_stays_closed_below_rate_threshold(): void
    {
        $breaker = new CircuitBreaker($this->integration);

        // 20 successes + 2 failures = ~9% failure rate over 22 requests.
        for ($i = 0; $i < 20; $i++) {
            $breaker->recordSuccess();
        }
        $breaker->recordFailure(FailureClass::Upstream);
        $breaker->recordFailure(FailureClass::Upstream);

        $breaker->enforce();
        $this->assertTrue(true);
    }

    public function test_client_errors_dilute_the_failure_rate(): void
    {
        $breaker = new CircuitBreaker($this->integration);

        // 5 upstream failures and 15 client errors: 25% upstream-failure rate
        // over 20 requests, below the 50% threshold. Client errors count
        // toward the denominator but not the numerator.
        for ($i = 0; $i < 5; $i++) {
            $breaker->recordFailure(FailureClass::Upstream);
        }
        for ($i = 0; $i < 15; $i++) {
            $breaker->recordFailure(FailureClass::Client);
        }

        $breaker->enforce();
        $this->assertTrue(true);
    }

    public function test_window_roll_drops_stale_failures(): void
    {
        Carbon::setTestNow(Carbon::createFromTimestamp(1_700_000_040));
        $breaker = new CircuitBreaker($this->integration);

        // Nine failures in the first window: under the floor, no trip.
        for ($i = 0; $i < 9; $i++) {
            $breaker->recordFailure(FailureClass::Upstream);
        }

        // Advance two full windows so neither current nor previous bucket sees
        // the old failures, then add a couple: still under the floor.
        Carbon::setTestNow(Carbon::now()->addSeconds(180));
        $breaker->recordFailure(FailureClass::Upstream);
        $breaker->recordFailure(FailureClass::Upstream);

        $breaker->enforce();
        $this->assertTrue(true);
    }

    public function test_half_open_probe_success_resets_window(): void
    {
        config(['integrations.circuit_breaker.minimum_requests' => 2]);
        $breaker = new CircuitBreaker($this->integration);

        // Trip it: 2 failures, 100% over the floor of 2.
        $breaker->recordFailure(FailureClass::Upstream);
        $breaker->recordFailure(FailureClass::Upstream);

        try {
            $breaker->enforce();
            $this->fail('Expected CircuitOpenException');
        } catch (CircuitOpenException) {
            // expected
        }

        // After cooldown, the probe is allowed and its success closes + resets.
        Carbon::setTestNow(Carbon::now()->addSeconds(31));
        $breaker->enforce(); // half-open probe
        $breaker->recordSuccess(); // closes and clears the window

        // A fresh failure shouldn't immediately re-trip from stale buckets.
        $breaker->recordFailure(FailureClass::Upstream);
        $breaker->enforce();
        $this->assertTrue(true);
    }

    public function test_current_failure_rate_is_null_before_any_outcome(): void
    {
        $strategy = new CircuitBreakerRateStrategy;

        $this->assertNull($strategy->currentFailureRate($this->integration->id));
    }

    public function test_current_failure_rate_reflects_recorded_outcomes(): void
    {
        $strategy = new CircuitBreakerRateStrategy;

        // 3 failures + 1 success = 4 outcomes, 75% failure rate. No floor applied.
        $strategy->recordOutcome($this->integration->id, true);
        $strategy->recordOutcome($this->integration->id, true);
        $strategy->recordOutcome($this->integration->id, true);
        $strategy->recordOutcome($this->integration->id, false);

        $this->assertEqualsWithDelta(75.0, $strategy->currentFailureRate($this->integration->id), 0.01);
    }

    public function test_inspect_surfaces_the_live_failure_rate(): void
    {
        $breaker = new CircuitBreaker($this->integration);
        $breaker->recordFailure(FailureClass::Upstream);
        $breaker->recordSuccess();

        $rate = $breaker->inspect()['failure_rate'];
        $this->assertNotNull($rate);
        $this->assertEqualsWithDelta(50.0, $rate, 0.01);
    }
}
