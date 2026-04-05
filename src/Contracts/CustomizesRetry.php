<?php

declare(strict_types=1);

namespace Integrations\Contracts;

interface CustomizesRetry
{
    /**
     * Determine if an exception thrown during a request callback is retryable.
     *
     * Return null to fall back to the default RetryHandler logic.
     */
    public function isRetryable(\Throwable $e): ?bool;

    /**
     * Calculate the retry delay in milliseconds for a retryable exception.
     *
     * Return null to fall back to the default RetryHandler logic.
     */
    public function retryDelayMs(\Throwable $e, int $attempt, ?int $statusCode): ?int;
}
