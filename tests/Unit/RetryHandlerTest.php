<?php

declare(strict_types=1);

namespace Integrations\Tests\Unit;

use Carbon\Carbon;
use GuzzleHttp\Exception\RequestException as GuzzleRequestException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Illuminate\Http\Client\ConnectionException;
use Integrations\Exceptions\RetriesExhaustedException;
use Integrations\RetryHandler;
use Integrations\Tests\TestCase;
use RuntimeException;

class RetryHandlerTest extends TestCase
{
    public function test_returns_on_success(): void
    {
        $result = RetryHandler::execute(fn () => 'hello');

        $this->assertSame('hello', $result);
    }

    public function test_throws_on_non_retryable_error(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('bad request');

        RetryHandler::execute(fn () => throw new RuntimeException('bad request'));
    }

    public function test_retries_on_connection_exception(): void
    {
        $attempts = 0;

        $result = RetryHandler::execute(
            callback: function () use (&$attempts) {
                $attempts++;
                if ($attempts < 3) {
                    throw new ConnectionException('timeout');
                }

                return 'recovered';
            },
            maxAttempts: 3,
            rateLimitDelayMs: 0,
            serverErrorBaseDelayMs: 0,
            defaultBaseDelayMs: 0,
        );

        $this->assertSame('recovered', $result);
        $this->assertSame(3, $attempts);
    }

    public function test_throws_retries_exhausted(): void
    {
        $this->expectException(RetriesExhaustedException::class);

        RetryHandler::execute(
            callback: fn () => throw new ConnectionException('timeout'),
            maxAttempts: 2,
            rateLimitDelayMs: 0,
            serverErrorBaseDelayMs: 0,
            defaultBaseDelayMs: 0,
        );
    }

    public function test_calls_on_retry_callback(): void
    {
        $retryAttempts = [];

        try {
            RetryHandler::execute(
                callback: fn () => throw new ConnectionException('timeout'),
                maxAttempts: 3,
                rateLimitDelayMs: 0,
                serverErrorBaseDelayMs: 0,
                defaultBaseDelayMs: 0,
                onRetry: function (int $attempt, \Throwable $_e) use (&$retryAttempts): void {
                    $retryAttempts[] = $attempt;
                },
            );
        } catch (RetriesExhaustedException) {
            // expected
        }

        $this->assertSame([1, 2], $retryAttempts);
    }

    public function test_retries_on_wrapped_connection_exception(): void
    {
        $attempts = 0;

        $result = RetryHandler::execute(
            callback: function () use (&$attempts) {
                $attempts++;
                if ($attempts < 2) {
                    throw new RuntimeException('SDK error', 0, new ConnectionException('timeout'));
                }

                return 'recovered';
            },
            maxAttempts: 3,
            rateLimitDelayMs: 0,
            serverErrorBaseDelayMs: 0,
            defaultBaseDelayMs: 0,
        );

        $this->assertSame('recovered', $result);
        $this->assertSame(2, $attempts);
    }

    public function test_retries_on_wrapped_guzzle_429(): void
    {
        $attempts = 0;

        $result = RetryHandler::execute(
            callback: function () use (&$attempts) {
                $attempts++;
                if ($attempts < 2) {
                    $request = new Request('GET', 'https://example.com');
                    $response = new Response(429);
                    $guzzle = new GuzzleRequestException('Rate limited', $request, $response);
                    throw new RuntimeException('SDK error', 0, $guzzle);
                }

                return 'recovered';
            },
            maxAttempts: 3,
            rateLimitDelayMs: 0,
            serverErrorBaseDelayMs: 0,
            defaultBaseDelayMs: 0,
        );

        $this->assertSame('recovered', $result);
        $this->assertSame(2, $attempts);
    }

    public function test_is_retryable_detects_wrapped_connection_exception(): void
    {
        $wrapped = new RuntimeException('SDK error', 0, new ConnectionException('timeout'));

        $this->assertTrue(RetryHandler::isRetryable($wrapped));
    }

    public function test_is_retryable_detects_wrapped_guzzle_503(): void
    {
        $request = new Request('GET', 'https://example.com');
        $response = new Response(503);
        $guzzle = new GuzzleRequestException('Unavailable', $request, $response);
        $wrapped = new RuntimeException('SDK error', 0, $guzzle);

        $this->assertTrue(RetryHandler::isRetryable($wrapped));
    }

    public function test_calculate_delay_uses_retry_after_numeric_header(): void
    {
        $request = new Request('GET', 'https://example.com');
        $response = new Response(429, ['Retry-After' => '5']);
        $e = new GuzzleRequestException('Rate limited', $request, $response);

        $this->assertSame(5000, RetryHandler::calculateDelayMs($e, 1));
    }

    public function test_calculate_delay_uses_retry_after_http_date_header(): void
    {
        Carbon::setTestNow('2026-04-05 12:00:00');

        $request = new Request('GET', 'https://example.com');
        $retryDate = Carbon::parse('2026-04-05 12:00:30')->toRfc7231String();
        $response = new Response(429, ['Retry-After' => $retryDate]);
        $e = new GuzzleRequestException('Rate limited', $request, $response);

        $delayMs = RetryHandler::calculateDelayMs($e, 1);
        $this->assertGreaterThanOrEqual(29000, $delayMs);
        $this->assertLessThanOrEqual(31000, $delayMs);

        Carbon::setTestNow();
    }

    public function test_calculate_delay_caps_retry_after_at_configured_max(): void
    {
        config(['integrations.retry.retry_after_max_seconds' => 10]);

        $request = new Request('GET', 'https://example.com');
        $response = new Response(429, ['Retry-After' => '3600']);
        $e = new GuzzleRequestException('Rate limited', $request, $response);

        $this->assertSame(10000, RetryHandler::calculateDelayMs($e, 1));
    }

    public function test_calculate_delay_uses_wrapped_guzzle_retry_after(): void
    {
        $request = new Request('GET', 'https://example.com');
        $response = new Response(429, ['Retry-After' => '10']);
        $guzzle = new GuzzleRequestException('Rate limited', $request, $response);
        $wrapped = new RuntimeException('SDK error', 0, $guzzle);

        $this->assertSame(10000, RetryHandler::calculateDelayMs($wrapped, 1));
    }

    public function test_calculate_delay_falls_back_without_retry_after(): void
    {
        $request = new Request('GET', 'https://example.com');
        $response = new Response(429);
        $e = new GuzzleRequestException('Rate limited', $request, $response);

        $this->assertSame(30_000, RetryHandler::calculateDelayMs($e, 1));
    }
}
