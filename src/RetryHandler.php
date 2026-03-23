<?php

declare(strict_types=1);

namespace Integrations;

use Closure;
use GuzzleHttp\Exception\RequestException as GuzzleRequestException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException as LaravelRequestException;
use Integrations\Exceptions\RetriesExhaustedException;

class RetryHandler
{
    /**
     * Execute a callback with retry logic for transient errors.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @param  list<int>  $retryableStatusCodes
     * @param  (Closure(int, \Throwable): void)|null  $onRetry
     * @return T
     *
     * @throws \Throwable
     */
    public static function execute(
        Closure $callback,
        int $maxRetries = 3,
        array $retryableStatusCodes = [429, 500, 502, 503, 504],
        int $rateLimitDelayMs = 30_000,
        int $serverErrorBaseDelayMs = 2_000,
        int $defaultBaseDelayMs = 1_000,
        ?Closure $onRetry = null,
    ): mixed {
        if ($maxRetries < 1) {
            throw new \InvalidArgumentException('$maxRetries must be at least 1.');
        }

        if ($rateLimitDelayMs < 0 || $serverErrorBaseDelayMs < 0 || $defaultBaseDelayMs < 0) {
            throw new \InvalidArgumentException('Delay values must be non-negative.');
        }

        $lastException = null;
        $retriesMade = 0;
        $exhausted = false;

        for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
            try {
                return $callback();
            } catch (\Throwable $e) {
                $lastException = $e;
                $statusCode = self::extractStatusCode($e);

                if (! self::isRetryableInternal($e, $statusCode, $retryableStatusCodes)) {
                    break;
                }

                if ($attempt >= $maxRetries) {
                    $exhausted = true;
                    break;
                }

                $retriesMade++;
                $delayMs = self::calculateDelay(
                    $statusCode, $attempt,
                    $rateLimitDelayMs, $serverErrorBaseDelayMs, $defaultBaseDelayMs,
                );

                if ($onRetry !== null) {
                    ($onRetry)($attempt, $e);
                }

                usleep($delayMs * 1000);
            }
        }

        if ($exhausted && $retriesMade > 0) {
            throw new RetriesExhaustedException($retriesMade, $lastException);
        }

        if ($lastException !== null) {
            throw $lastException;
        }

        throw new \RuntimeException('Retry logic exhausted without result.');
    }

    /**
     * Check if an exception is retryable with default status codes.
     */
    public static function isRetryable(\Throwable $e): bool
    {
        $statusCode = self::extractStatusCode($e);

        return self::isRetryableInternal($e, $statusCode, [429, 500, 502, 503, 504]);
    }

    /**
     * Calculate the retry delay in milliseconds for a given exception and attempt.
     */
    public static function calculateDelayMs(\Throwable $e, int $attempt): int
    {
        $statusCode = self::extractStatusCode($e);

        return self::calculateDelay($statusCode, $attempt, 30_000, 2_000, 1_000);
    }

    private static function extractStatusCode(\Throwable $e): ?int
    {
        if ($e instanceof LaravelRequestException) {
            return $e->response->status();
        }

        if ($e instanceof GuzzleRequestException && $e->getResponse() !== null) {
            return $e->getResponse()->getStatusCode();
        }

        return null;
    }

    /**
     * @param  list<int>  $retryableStatusCodes
     */
    private static function isRetryableInternal(\Throwable $e, ?int $statusCode, array $retryableStatusCodes): bool
    {
        if ($e instanceof ConnectionException) {
            return true;
        }

        if ($statusCode !== null && in_array($statusCode, $retryableStatusCodes, true)) {
            return true;
        }

        return false;
    }

    private static function calculateDelay(
        ?int $statusCode,
        int $attempt,
        int $rateLimitDelayMs,
        int $serverErrorBaseDelayMs,
        int $defaultBaseDelayMs,
    ): int {
        if ($statusCode === 429) {
            return $rateLimitDelayMs;
        }

        if ($statusCode !== null && $statusCode >= 500) {
            return $attempt * $serverErrorBaseDelayMs;
        }

        return $attempt * $defaultBaseDelayMs;
    }
}
