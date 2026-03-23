<?php

declare(strict_types=1);

namespace Integrations\Tests\Unit;

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
            maxRetries: 3,
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
            maxRetries: 2,
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
                maxRetries: 3,
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
}
