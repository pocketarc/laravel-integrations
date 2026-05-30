<?php

declare(strict_types=1);

namespace Integrations\Tests\Unit;

use Carbon\Carbon;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Integrations\CircuitBreaker;
use Integrations\Enums\FailureClass;
use Integrations\Exceptions\CircuitOpenException;
use Integrations\IntegrationManager;
use Integrations\Models\Integration;
use Integrations\RetryHandler;
use Integrations\Tests\Fixtures\TestProvider;
use Integrations\Tests\TestCase;

class CircuitBreakerTest extends TestCase
{
    private Integration $integration;

    protected function setUp(): void
    {
        parent::setUp();

        app(IntegrationManager::class)->register('test', TestProvider::class);
        $this->integration = Integration::create(['provider' => 'test', 'name' => 'Test']);
        $this->integration->refresh();

        // These tests assert consecutive-count semantics; the rate strategy
        // (the package default) has its own suite.
        config(['integrations.circuit_breaker.strategy' => 'count']);

        // Don't sleep; throw rate limit exceeded immediately.
        config(['integrations.rate_limiting.max_wait_seconds' => 0]);
    }

    public function test_breaker_opens_after_threshold_consecutive_failures(): void
    {
        config(['integrations.circuit_breaker.threshold' => 3]);

        for ($i = 0; $i < 3; $i++) {
            try {
                // Disable retries so each outer call counts as exactly one
                // failure. A connection error classifies as an upstream fault.
                $this->integration->at('/api/x')
                    ->withAttempts(1)
                    ->get(function (): array {
                        throw new ConnectionException('boom');
                    });
            } catch (ConnectionException) {
                // expected
            }
        }

        $this->expectException(CircuitOpenException::class);

        $this->integration->at('/api/x')->get(fn (): array => ['ok' => true]);
    }

    public function test_circuit_open_exception_is_not_retryable(): void
    {
        config(['integrations.circuit_breaker.threshold' => 2]);

        $breaker = new CircuitBreaker($this->integration);
        $breaker->recordFailure(FailureClass::Upstream);
        $breaker->recordFailure(FailureClass::Upstream);

        try {
            $breaker->enforce();
            $this->fail('Expected CircuitOpenException');
        } catch (CircuitOpenException $e) {
            $this->assertFalse(RetryHandler::isRetryable($e));
        }
    }

    public function test_breaker_does_not_open_on_client_errors(): void
    {
        config(['integrations.circuit_breaker.threshold' => 3]);

        $breaker = new CircuitBreaker($this->integration);

        for ($i = 0; $i < 10; $i++) {
            $breaker->recordFailure(FailureClass::Client);
        }

        // No throw: a client error (4xx other than 429) doesn't count.
        $breaker->enforce();
        $this->assertTrue(true);
    }

    public function test_breaker_does_not_count_throttles(): void
    {
        config(['integrations.circuit_breaker.threshold' => 3]);

        $breaker = new CircuitBreaker($this->integration);

        // A 429 throttle is the rate limiter's concern; it must NOT open the
        // availability breaker (the upstream is healthy, just pacing us).
        for ($i = 0; $i < 5; $i++) {
            $breaker->recordFailure(FailureClass::Throttle);
        }

        $breaker->enforce();
        $this->assertTrue(true);
    }

    public function test_breaker_counts_upstream_failures(): void
    {
        config(['integrations.circuit_breaker.threshold' => 2]);

        $breaker = new CircuitBreaker($this->integration);

        for ($i = 0; $i < 2; $i++) {
            $breaker->recordFailure(FailureClass::Upstream);
        }

        $this->expectException(CircuitOpenException::class);
        $breaker->enforce();
    }

    public function test_success_resets_failure_count(): void
    {
        config(['integrations.circuit_breaker.threshold' => 3]);

        $breaker = new CircuitBreaker($this->integration);

        $breaker->recordFailure(FailureClass::Upstream);
        $breaker->recordFailure(FailureClass::Upstream);
        $breaker->recordSuccess();

        // Two more failures should not be enough to open (counter was reset).
        $breaker->recordFailure(FailureClass::Upstream);
        $breaker->recordFailure(FailureClass::Upstream);

        $breaker->enforce(); // should not throw
        $this->assertTrue(true);
    }

    public function test_breaker_reopens_after_cooldown_for_half_open_probe(): void
    {
        config(['integrations.circuit_breaker.threshold' => 2]);
        config(['integrations.circuit_breaker.cooldown_seconds' => 30]);

        $breaker = new CircuitBreaker($this->integration);

        $breaker->recordFailure(FailureClass::Upstream);
        $breaker->recordFailure(FailureClass::Upstream);

        try {
            $breaker->enforce();
            $this->fail('Expected CircuitOpenException');
        } catch (CircuitOpenException) {
            // expected
        }

        // Travel past the cooldown; the next request becomes the probe.
        Carbon::setTestNow(Carbon::now()->addSeconds(31));

        $breaker->enforce(); // should not throw, half-open probe
        $this->assertTrue(true);
    }

    public function test_half_open_success_closes_the_breaker(): void
    {
        config(['integrations.circuit_breaker.threshold' => 2]);
        config(['integrations.circuit_breaker.cooldown_seconds' => 10]);

        $breaker = new CircuitBreaker($this->integration);

        $breaker->recordFailure(FailureClass::Upstream);
        $breaker->recordFailure(FailureClass::Upstream);

        Carbon::setTestNow(Carbon::now()->addSeconds(15));
        $breaker->enforce(); // half-open

        $breaker->recordSuccess(); // close it

        // Future failures should rebuild from zero.
        $breaker->recordFailure(FailureClass::Upstream);
        $breaker->enforce(); // still closed
        $this->assertTrue(true);
    }

    public function test_half_open_failure_reopens_with_fresh_cooldown(): void
    {
        config(['integrations.circuit_breaker.threshold' => 2]);
        config(['integrations.circuit_breaker.cooldown_seconds' => 10]);

        $breaker = new CircuitBreaker($this->integration);

        $breaker->recordFailure(FailureClass::Upstream);
        $breaker->recordFailure(FailureClass::Upstream);

        Carbon::setTestNow(Carbon::now()->addSeconds(15));

        $breaker->enforce(); // half-open

        $breaker->recordFailure(FailureClass::Upstream);

        // Should be open again.
        $this->expectException(CircuitOpenException::class);
        $breaker->enforce();
    }

    public function test_disabling_the_breaker_skips_all_logic(): void
    {
        config(['integrations.circuit_breaker.enabled' => false]);
        config(['integrations.circuit_breaker.threshold' => 2]);

        $breaker = new CircuitBreaker($this->integration);

        for ($i = 0; $i < 10; $i++) {
            $breaker->recordFailure(FailureClass::Upstream);
        }

        $breaker->enforce();
        $this->assertTrue(true);
    }

    public function test_non_counting_failure_does_not_open(): void
    {
        config(['integrations.circuit_breaker.threshold' => 2]);

        $breaker = new CircuitBreaker($this->integration);

        // Build the breaker just below the threshold.
        $breaker->recordFailure(FailureClass::Upstream);

        // An unknown failure carries no positive evidence and must not count;
        // otherwise a single trip could wedge the breaker open.
        $breaker->recordFailure(FailureClass::Unknown);

        $breaker->enforce(); // should not throw, still closed
        $this->assertTrue(true);
    }

    public function test_only_one_concurrent_probe_can_enter_half_open(): void
    {
        config(['integrations.circuit_breaker.threshold' => 2]);
        config(['integrations.circuit_breaker.cooldown_seconds' => 10]);

        // Open the breaker via a first instance.
        $breakerA = new CircuitBreaker($this->integration);
        $breakerA->recordFailure(FailureClass::Upstream);
        $breakerA->recordFailure(FailureClass::Upstream);

        // Two separate instances simulate two workers seeing the same
        // open state at the same instant.
        $breakerB = new CircuitBreaker($this->integration);

        Carbon::setTestNow(Carbon::now()->addSeconds(15));

        // First worker claims the probe slot atomically.
        $breakerA->enforce();

        // Second worker arrives a moment later and sees the slot taken.
        $caught = null;
        try {
            $breakerB->enforce();
        } catch (CircuitOpenException $e) {
            $caught = $e;
        }

        $this->assertInstanceOf(CircuitOpenException::class, $caught);
    }

    public function test_probe_slot_is_released_after_success(): void
    {
        config(['integrations.circuit_breaker.threshold' => 2]);
        config(['integrations.circuit_breaker.cooldown_seconds' => 10]);

        $breaker = new CircuitBreaker($this->integration);
        $breaker->recordFailure(FailureClass::Upstream);
        $breaker->recordFailure(FailureClass::Upstream);

        Carbon::setTestNow(Carbon::now()->addSeconds(15));

        $breaker->enforce();    // claims probe slot
        $breaker->recordSuccess(); // releases it, closes breaker

        // Build the breaker back up and verify the probe slot can be
        // reclaimed on the next cycle.
        $breaker->recordFailure(FailureClass::Upstream);
        $breaker->recordFailure(FailureClass::Upstream);

        Carbon::setTestNow(Carbon::now()->addSeconds(15));
        $breaker->enforce(); // should claim a fresh slot, not throw
        $this->assertTrue(true);
    }

    protected function tearDown(): void
    {
        // Always reset frozen time, even when a test bailed early or threw,
        // so mocked Carbon doesn't leak into the next test.
        Carbon::setTestNow();
        Cache::flush();
        parent::tearDown();
    }
}
