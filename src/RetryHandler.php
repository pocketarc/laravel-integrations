<?php

declare(strict_types=1);

namespace Integrations;

use Closure;
use Illuminate\Http\Client\ConnectionException;
use Integrations\Exceptions\RetriesExhaustedException;
use Integrations\Support\ResponseHelper;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

class RetryHandler
{
    /**
     * Execute a callback with retry logic for transient errors.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @param  list<int>  $retryableStatusCodes
     * @param  (Closure(int, Throwable): void)|null  $onRetry
     * @return T
     *
     * @throws Throwable
     */
    public static function execute(
        Closure $callback,
        int $maxAttempts = 3,
        array $retryableStatusCodes = [429, 500, 502, 503, 504],
        int $rateLimitDelayMs = 30_000,
        int $serverErrorBaseDelayMs = 2_000,
        int $defaultBaseDelayMs = 1_000,
        ?Closure $onRetry = null,
    ): mixed {
        if ($maxAttempts < 1) {
            throw new InvalidArgumentException('$maxAttempts must be at least 1.');
        }

        if ($rateLimitDelayMs < 0 || $serverErrorBaseDelayMs < 0 || $defaultBaseDelayMs < 0) {
            throw new InvalidArgumentException('Delay values must be non-negative.');
        }

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            try {
                return $callback();
            } catch (Throwable $e) {
                $statusCode = ResponseHelper::extractStatusCode($e);

                if (! self::isRetryableInternal($e, $statusCode, $retryableStatusCodes)) {
                    throw $e;
                }

                if ($attempt >= $maxAttempts) {
                    throw new RetriesExhaustedException($attempt - 1, $e);
                }

                if ($onRetry !== null) {
                    ($onRetry)($attempt, $e);
                }

                $delayMs = self::calculateDelay(
                    $statusCode, $attempt,
                    $rateLimitDelayMs, $serverErrorBaseDelayMs, $defaultBaseDelayMs,
                );
                usleep($delayMs * 1000);
            }
        }

        throw new RuntimeException('Retry logic exhausted without result.');
    }

    /**
     * Check if an exception is retryable with default status codes.
     */
    public static function isRetryable(Throwable $e): bool
    {
        $statusCode = ResponseHelper::extractStatusCode($e);

        return self::isRetryableInternal($e, $statusCode, [429, 500, 502, 503, 504]);
    }

    /**
     * Calculate the retry delay in milliseconds for a given exception and attempt.
     */
    public static function calculateDelayMs(Throwable $e, int $attempt): int
    {
        $attempt = max(1, $attempt);
        $statusCode = ResponseHelper::extractStatusCode($e);

        return self::calculateDelay($statusCode, $attempt, 30_000, 2_000, 1_000);
    }

    /**
     * @param  list<int>  $retryableStatusCodes
     */
    private static function isRetryableInternal(Throwable $e, ?int $statusCode, array $retryableStatusCodes): bool
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
