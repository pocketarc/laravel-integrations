<?php

declare(strict_types=1);

namespace Integrations\Tests\Unit;

use Integrations\Exceptions\RetriesExhaustedException;
use Integrations\Exceptions\RetryableException;
use Integrations\IntegrationManager;
use Integrations\Models\Integration;
use Integrations\RetryHandler;
use Integrations\Tests\Fixtures\CustomizesRetryProvider;
use Integrations\Tests\Fixtures\PlainProvider;
use Integrations\Tests\TestCase;
use RuntimeException;

class RetryableExceptionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        CustomizesRetryProvider::reset();
    }

    public function test_is_retryable_detects_retryable_exception(): void
    {
        $e = new RetryableException('temporary failure');

        $this->assertTrue(RetryHandler::isRetryable($e));
    }

    public function test_is_retryable_detects_wrapped_retryable_exception(): void
    {
        $retryable = new RetryableException('temporary failure');
        $wrapped = new RuntimeException('wrapper', 0, $retryable);

        $this->assertTrue(RetryHandler::isRetryable($wrapped));
    }

    public function test_retries_on_retryable_exception(): void
    {
        $attempts = 0;

        $result = RetryHandler::execute(
            callback: function () use (&$attempts) {
                $attempts++;
                if ($attempts < 3) {
                    throw new RetryableException('temporary');
                }

                return 'recovered';
            },
            maxAttempts: 3,
            defaultBaseDelayMs: 0,
        );

        $this->assertSame('recovered', $result);
        $this->assertSame(3, $attempts);
    }

    public function test_throws_retries_exhausted_for_retryable_exception(): void
    {
        $this->expectException(RetriesExhaustedException::class);

        RetryHandler::execute(
            callback: fn () => throw new RetryableException('always fails'),
            maxAttempts: 2,
            defaultBaseDelayMs: 0,
        );
    }

    public function test_calculate_delay_uses_retry_after_seconds(): void
    {
        $e = new RetryableException('rate limited', retryAfterSeconds: 10);

        $this->assertSame(10_000, RetryHandler::calculateDelayMs($e, 1));
    }

    public function test_calculate_delay_caps_retry_after_seconds_at_configured_max(): void
    {
        config(['integrations.retry.retry_after_max_seconds' => 5]);

        $e = new RetryableException('rate limited', retryAfterSeconds: 3600);

        $this->assertSame(5000, RetryHandler::calculateDelayMs($e, 1));
    }

    public function test_calculate_delay_falls_back_without_retry_after_seconds(): void
    {
        $e = new RetryableException('temporary');

        // Falls through to default delay logic: attempt * 1000ms
        $this->assertSame(1000, RetryHandler::calculateDelayMs($e, 1));
        $this->assertSame(2000, RetryHandler::calculateDelayMs($e, 2));
    }

    public function test_retryable_exception_takes_priority_over_customizes_retry(): void
    {
        CustomizesRetryProvider::$isRetryable = false;
        CustomizesRetryProvider::$delayMs = null;

        $manager = app(IntegrationManager::class);
        $manager->register('retryable-test', CustomizesRetryProvider::class);

        $integration = Integration::create([
            'provider' => 'retryable-test',
            'name' => 'Retryable Test',
        ]);
        $integration->refresh();

        $attempts = 0;

        try {
            $integration->request(
                endpoint: '/api/test',
                method: 'GET',
                callback: function () use (&$attempts) {
                    $attempts++;
                    throw new RetryableException('temporary', retryAfterSeconds: 0);
                },
                maxAttempts: 3,
            );
            $this->fail('Expected RetryableException was not thrown.');
        } catch (RetryableException) {
            // expected after retries exhausted
        }

        // Provider says not retryable, but RetryableException overrides
        $this->assertSame(3, $attempts);
    }

    public function test_retryable_exception_max_attempts_caps_retries(): void
    {
        $manager = app(IntegrationManager::class);
        $manager->register('retryable-cap-test', PlainProvider::class);

        $integration = Integration::create([
            'provider' => 'retryable-cap-test',
            'name' => 'Cap Test',
        ]);
        $integration->refresh();

        $attempts = 0;

        try {
            $integration->request(
                endpoint: '/api/test',
                method: 'GET',
                callback: function () use (&$attempts) {
                    $attempts++;
                    throw new RetryableException('temporary', retryAfterSeconds: 0, maxAttempts: 2);
                },
                maxAttempts: 5,
            );
            $this->fail('Expected RetryableException was not thrown.');
        } catch (RetryableException) {
            // expected
        }

        // maxAttempts on the exception caps at 2 even though executor allows 5
        $this->assertSame(2, $attempts);
    }
}
