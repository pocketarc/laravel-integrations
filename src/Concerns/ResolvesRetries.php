<?php

declare(strict_types=1);

namespace Integrations\Concerns;

use Integrations\Contracts\CustomizesRetry;
use Integrations\Exceptions\RetryableException;
use Integrations\RequestExecutor;
use Integrations\RetryHandler;
use Integrations\Support\FailureClassifier;
use Integrations\Support\ResponseHelper;
use Throwable;

/**
 * Retry-decision logic for the RequestExecutor: whether to retry, how long to
 * wait, and how to find a RetryableException in the chain. Kept separate from
 * the executor's request/response plumbing.
 *
 * @mixin RequestExecutor
 */
trait ResolvesRetries
{
    private function shouldRetry(Throwable $e, int $attempt, int $maxAttempts): bool
    {
        $retryableException = self::findRetryableException($e);

        if ($retryableException === null && ! $this->isRetryableViaProvider($e)) {
            return false;
        }

        $cap = $retryableException?->maxAttempts;

        return $attempt < ($cap !== null ? min($maxAttempts, $cap) : $maxAttempts);
    }

    private function isRetryableViaProvider(Throwable $e): bool
    {
        $provider = $this->integration->provider();
        $providerRetryable = $provider instanceof CustomizesRetry ? $provider->isRetryable($e) : null;
        if ($providerRetryable !== null) {
            return $providerRetryable;
        }

        if (RetryHandler::isRetryable($e)) {
            return true;
        }

        // Last resort: let classification decide, so a provider that implements
        // only ClassifiesFailures (no CustomizesRetry) still retries upstream
        // faults and throttles.
        return FailureClassifier::classify($e, $provider)->isRetryable();
    }

    private function resolveRetryDelay(Throwable $e, int $attempt): int
    {
        // RetryableException::retryAfterSeconds takes priority over CustomizesRetry.
        // RetryHandler::calculateDelayMs already honors it, so route there directly.
        $retryableException = self::findRetryableException($e);
        if ($retryableException !== null && $retryableException->retryAfterSeconds !== null) {
            return RetryHandler::calculateDelayMs($e, $attempt);
        }

        $provider = $this->integration->provider();
        $statusCode = ResponseHelper::extractStatusCode($e);
        $providerDelay = $provider instanceof CustomizesRetry ? $provider->retryDelayMs($e, $attempt, $statusCode) : null;

        return $providerDelay ?? RetryHandler::calculateDelayMs($e, $attempt);
    }

    private static function findRetryableException(Throwable $e): ?RetryableException
    {
        for ($current = $e; $current !== null; $current = $current->getPrevious()) {
            if ($current instanceof RetryableException) {
                return $current;
            }
        }

        return null;
    }
}
