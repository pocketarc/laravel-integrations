<?php

declare(strict_types=1);

namespace Integrations\Tests\Unit;

use Illuminate\Http\Client\ConnectionException;
use Integrations\IntegrationManager;
use Integrations\Models\Integration;
use Integrations\Tests\Fixtures\RetryTestProvider;
use Integrations\Tests\TestCase;
use RuntimeException;

class CustomizesRetryTest extends TestCase
{
    private Integration $integration;

    protected function setUp(): void
    {
        parent::setUp();

        RetryTestProvider::reset();

        $manager = app(IntegrationManager::class);
        $manager->register('retry-test', RetryTestProvider::class);

        $this->integration = Integration::create([
            'provider' => 'retry-test',
            'name' => 'Retry Test',
        ]);
        $this->integration->refresh();
    }

    public function test_provider_can_mark_unknown_exception_as_retryable(): void
    {
        RetryTestProvider::$isRetryable = true;
        RetryTestProvider::$delayMs = 0;
        $attempts = 0;

        try {
            $this->integration->request(
                endpoint: '/api/test',
                method: 'GET',
                callback: function () use (&$attempts) {
                    $attempts++;
                    throw new RuntimeException('SDK-specific error');
                },
                maxAttempts: 3,
            );
        } catch (RuntimeException) {
            // expected
        }

        $this->assertSame(3, $attempts);
    }

    public function test_provider_can_prevent_retry_for_normally_retryable_exception(): void
    {
        RetryTestProvider::$isRetryable = false;
        $attempts = 0;

        try {
            $this->integration->request(
                endpoint: '/api/test',
                method: 'GET',
                callback: function () use (&$attempts) {
                    $attempts++;
                    throw new ConnectionException('timeout');
                },
                maxAttempts: 3,
            );
        } catch (ConnectionException) {
            // expected
        }

        $this->assertSame(1, $attempts);
    }

    public function test_provider_returning_null_falls_back_to_core_logic(): void
    {
        RetryTestProvider::$isRetryable = null;
        $attempts = 0;

        try {
            $this->integration->request(
                endpoint: '/api/test',
                method: 'GET',
                callback: function () use (&$attempts) {
                    $attempts++;
                    throw new RuntimeException('Unknown error');
                },
                maxAttempts: 3,
            );
        } catch (RuntimeException) {
            // expected: core doesn't recognise this as retryable
        }

        $this->assertSame(1, $attempts);
    }

    public function test_provider_custom_delay_is_used(): void
    {
        RetryTestProvider::$isRetryable = true;
        RetryTestProvider::$delayMs = 0;
        $attempts = 0;

        try {
            $this->integration->request(
                endpoint: '/api/test',
                method: 'GET',
                callback: function () use (&$attempts) {
                    $attempts++;
                    throw new RuntimeException('SDK error');
                },
                maxAttempts: 2,
            );
        } catch (RuntimeException) {
            // expected
        }

        $this->assertSame(2, $attempts);
        $this->assertSame(1, RetryTestProvider::$delayCallCount);
    }

    public function test_provider_delay_receives_status_code(): void
    {
        RetryTestProvider::$isRetryable = null;
        RetryTestProvider::$capturedStatusCode = 'not-called';
        RetryTestProvider::$delayMs = 0;

        $attempts = 0;

        try {
            $this->integration->request(
                endpoint: '/api/test',
                method: 'GET',
                callback: function () use (&$attempts) {
                    $attempts++;
                    throw new ConnectionException('timeout');
                },
                maxAttempts: 2,
            );
        } catch (\Throwable) {
            // expected
        }

        $this->assertNull(RetryTestProvider::$capturedStatusCode);
    }
}
