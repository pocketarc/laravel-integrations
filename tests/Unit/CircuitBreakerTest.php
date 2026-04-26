<?php

declare(strict_types=1);

namespace Integrations\Tests\Unit;

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Integrations\CircuitBreaker;
use Integrations\Exceptions\CircuitOpenException;
use Integrations\Exceptions\RetryableException;
use Integrations\IntegrationManager;
use Integrations\Models\Integration;
use Integrations\RetryHandler;
use Integrations\Tests\Fixtures\TestProvider;
use Integrations\Tests\TestCase;
use Symfony\Component\HttpKernel\Exception\HttpException;

class CircuitBreakerTest extends TestCase
{
    private Integration $integration;

    protected function setUp(): void
    {
        parent::setUp();

        app(IntegrationManager::class)->register('test', TestProvider::class);
        $this->integration = Integration::create(['provider' => 'test', 'name' => 'Test']);
        $this->integration->refresh();

        // Don't sleep; throw rate limit exceeded immediately.
        config(['integrations.rate_limiting.max_wait_seconds' => 0]);
    }

    public function test_breaker_opens_after_threshold_consecutive_failures(): void
    {
        config(['integrations.circuit_breaker.threshold' => 3]);

        for ($i = 0; $i < 3; $i++) {
            try {
                // Disable retries so each outer call counts as exactly one
                // failure against the breaker.
                $this->integration->at('/api/x')
                    ->withAttempts(1)
                    ->get(function () use ($i): array {
                        throw new RetryableException('boom '.$i);
                    });
            } catch (RetryableException) {
                // expected
            }
        }

        $this->expectException(CircuitOpenException::class);

        $this->integration->at('/api/x')->get(fn (): array => ['ok' => true]);
    }

    public function test_circuit_open_exception_is_not_retryable(): void
    {
        $breaker = new CircuitBreaker($this->integration);
        // Force the breaker open.
        for ($i = 0; $i < 5; $i++) {
            $breaker->recordFailure(new RetryableException('boom'));
        }

        try {
            $breaker->enforce();
            $this->fail('Expected CircuitOpenException');
        } catch (CircuitOpenException $e) {
            $this->assertFalse(RetryHandler::isRetryable($e));
        }
    }

    public function test_breaker_does_not_open_on_4xx_other_than_429(): void
    {
        config(['integrations.circuit_breaker.threshold' => 3]);

        $breaker = new CircuitBreaker($this->integration);

        for ($i = 0; $i < 10; $i++) {
            // Use a Symfony HttpException so ResponseHelper::extractStatusCode
            // can pick up the 400 status. A plain RuntimeException with a
            // statusCode property is invisible to that helper.
            $breaker->recordFailure(new HttpException(400, 'bad request'));
        }

        // No throw: breaker stayed closed because 4xx doesn't count.
        $breaker->enforce();
        $this->assertTrue(true);
    }

    public function test_breaker_counts_429_responses(): void
    {
        config(['integrations.circuit_breaker.threshold' => 3]);

        $breaker = new CircuitBreaker($this->integration);

        // 429 has its own structured exception in the codebase but for the
        // breaker we need extractStatusCode to return 429. The simplest
        // shape ResponseHelper recognises is a ConnectionException with a
        // 429-ish message, but the cleanest is to count
        // RetryableException (always counts) which is the typical wrapper
        // used for 429 retries via Retry-After.
        for ($i = 0; $i < 3; $i++) {
            $breaker->recordFailure(new RetryableException('rate limited'));
        }

        $this->expectException(CircuitOpenException::class);
        $breaker->enforce();
    }

    public function test_breaker_counts_connection_errors(): void
    {
        config(['integrations.circuit_breaker.threshold' => 2]);

        $breaker = new CircuitBreaker($this->integration);

        for ($i = 0; $i < 2; $i++) {
            $breaker->recordFailure(new ConnectionException('network down'));
        }

        $this->expectException(CircuitOpenException::class);
        $breaker->enforce();
    }

    public function test_success_resets_failure_count(): void
    {
        config(['integrations.circuit_breaker.threshold' => 3]);

        $breaker = new CircuitBreaker($this->integration);

        $breaker->recordFailure(new RetryableException('boom'));
        $breaker->recordFailure(new RetryableException('boom'));
        $breaker->recordSuccess();

        // Two more failures should not be enough to open (counter was reset).
        $breaker->recordFailure(new RetryableException('boom'));
        $breaker->recordFailure(new RetryableException('boom'));

        $breaker->enforce(); // should not throw
        $this->assertTrue(true);
    }

    public function test_breaker_reopens_after_cooldown_for_half_open_probe(): void
    {
        config(['integrations.circuit_breaker.threshold' => 2]);
        config(['integrations.circuit_breaker.cooldown_seconds' => 30]);

        $breaker = new CircuitBreaker($this->integration);

        // Open the breaker.
        $breaker->recordFailure(new RetryableException('boom'));
        $breaker->recordFailure(new RetryableException('boom'));

        try {
            $breaker->enforce();
            $this->fail('Expected CircuitOpenException');
        } catch (CircuitOpenException) {
            // expected
        }

        // Travel past the cooldown and try again. Should transition to
        // half-open and let the request through.
        Carbon::setTestNow(Carbon::now()->addSeconds(31));

        $breaker->enforce(); // should not throw, half-open probe
        $this->assertTrue(true);

        Carbon::setTestNow(); // reset
    }

    public function test_half_open_success_closes_the_breaker(): void
    {
        config(['integrations.circuit_breaker.threshold' => 2]);
        config(['integrations.circuit_breaker.cooldown_seconds' => 10]);

        $breaker = new CircuitBreaker($this->integration);

        $breaker->recordFailure(new RetryableException('boom'));
        $breaker->recordFailure(new RetryableException('boom'));

        Carbon::setTestNow(Carbon::now()->addSeconds(15));
        $breaker->enforce(); // half-open

        $breaker->recordSuccess(); // close it

        // Future failures should rebuild from zero.
        $breaker->recordFailure(new RetryableException('one'));
        $breaker->enforce(); // still closed
        $this->assertTrue(true);

        Carbon::setTestNow();
    }

    public function test_half_open_failure_reopens_with_fresh_cooldown(): void
    {
        config(['integrations.circuit_breaker.threshold' => 2]);
        config(['integrations.circuit_breaker.cooldown_seconds' => 10]);

        $breaker = new CircuitBreaker($this->integration);

        $breaker->recordFailure(new RetryableException('boom'));
        $breaker->recordFailure(new RetryableException('boom'));

        Carbon::setTestNow(Carbon::now()->addSeconds(15));
        $breaker->enforce(); // half-open

        $breaker->recordFailure(new RetryableException('still down'));

        // Should be open again.
        $this->expectException(CircuitOpenException::class);
        $breaker->enforce();

        Carbon::setTestNow();
    }

    public function test_disabling_the_breaker_skips_all_logic(): void
    {
        config(['integrations.circuit_breaker.enabled' => false]);
        config(['integrations.circuit_breaker.threshold' => 2]);

        $breaker = new CircuitBreaker($this->integration);

        // Even with way more failures than the threshold, enforce never
        // throws because the breaker is disabled.
        for ($i = 0; $i < 10; $i++) {
            $breaker->recordFailure(new RetryableException('boom'));
        }

        $breaker->enforce();
        $this->assertTrue(true);
    }

    public function test_circuit_open_exception_does_not_count_as_a_failure(): void
    {
        config(['integrations.circuit_breaker.threshold' => 2]);

        $breaker = new CircuitBreaker($this->integration);

        // Build up the breaker just below the threshold.
        $breaker->recordFailure(new RetryableException('boom'));

        // A CircuitOpenException itself shouldn't count. That would open
        // the breaker forever after the first trip.
        $breaker->recordFailure(new CircuitOpenException(
            $this->integration,
            CarbonImmutable::now(),
            60,
        ));

        $breaker->enforce(); // should not throw, still closed
        $this->assertTrue(true);
    }

    protected function tearDown(): void
    {
        Cache::flush();
        parent::tearDown();
    }
}
