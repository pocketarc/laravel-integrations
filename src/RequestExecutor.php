<?php

declare(strict_types=1);

namespace Integrations;

use Carbon\CarbonInterface;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Integrations\Contracts\HasScheduledSync;
use Integrations\Contracts\RedactsRequestData;
use Integrations\Events\RequestCompleted;
use Integrations\Events\RequestFailed;
use Integrations\Exceptions\RateLimitExceededException;
use Integrations\Models\Integration;
use Integrations\Models\IntegrationRequest;
use Integrations\Support\Config;
use Integrations\Support\Redactor;
use Integrations\Support\ResponseHelper;
use InvalidArgumentException;

final class RequestExecutor
{
    private ?int $lastCreatedRequestId = null;

    private readonly RequestCache $cache;

    public function __construct(
        private readonly Integration $integration,
    ) {
        $this->cache = new RequestCache($integration);
    }

    /**
     * Execute a request against the integration, with caching, retries, and logging.
     *
     * @param  (Closure(): mixed)|null  $callback
     */
    public function execute(
        string $endpoint,
        string $method,
        ?Closure $callback,
        ?Model $relatedTo,
        ?string $encodedRequestData,
        ?CarbonInterface $cacheFor,
        bool $serveStale,
        ?int $retryOfId,
        int $maxRetries,
    ): mixed {
        $this->enforceRateLimit();

        if ($cacheFor !== null) {
            $cached = $this->cache->serve($endpoint, $method, $encodedRequestData);
            if ($cached !== null) {
                return $cached;
            }
        }

        if ($callback === null) {
            return null;
        }

        if ($maxRetries > 1) {
            return $this->requestWithRetries(
                $endpoint, $method, $callback, $relatedTo,
                $encodedRequestData, $cacheFor, $serveStale, $maxRetries, $retryOfId,
            );
        }

        return $this->executeRequest(
            $endpoint, $method, $callback, $relatedTo,
            $encodedRequestData, $cacheFor, $serveStale, $retryOfId,
        );
    }

    /**
     * @param  Closure(): mixed  $callback
     */
    private function requestWithRetries(
        string $endpoint,
        string $method,
        Closure $callback,
        ?Model $relatedTo,
        ?string $encodedRequestData,
        ?CarbonInterface $cacheFor,
        bool $serveStale,
        int $maxRetries,
        ?int $retryOfId = null,
    ): mixed {
        $firstRequestId = $retryOfId;

        $this->lastCreatedRequestId = null;

        for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
            $isLastAttempt = $attempt >= $maxRetries;
            $allowStale = $serveStale && $isLastAttempt;

            try {
                $result = $this->executeRequest(
                    $endpoint, $method, $callback, $relatedTo,
                    $encodedRequestData, $cacheFor, $allowStale,
                    retryOfId: $firstRequestId,
                );

                $firstRequestId ??= $this->lastCreatedRequestId;

                return $result;
            } catch (\Throwable $e) {
                $firstRequestId ??= $this->lastCreatedRequestId;

                if (! RetryHandler::isRetryable($e) || $isLastAttempt) {
                    return $this->serveStaleOrRethrow($e, $serveStale && ! $allowStale, $endpoint, $method, $encodedRequestData);
                }

                usleep(RetryHandler::calculateDelayMs($e, $attempt) * 1000);
            }
        }

        throw new \RuntimeException('Retry logic exhausted without result.');
    }

    /**
     * @param  Closure(): mixed  $callback
     */
    private function executeRequest(
        string $endpoint,
        string $method,
        Closure $callback,
        ?Model $relatedTo,
        ?string $encodedRequestData,
        ?CarbonInterface $cacheFor,
        bool $serveStale,
        ?int $retryOfId = null,
    ): mixed {
        $startTime = microtime(true);
        $responseSuccess = false;
        $responseCode = null;
        $responseData = null;
        $error = null;
        $result = null;

        try {
            $result = $callback();
            $responseSuccess = true;

            [$responseCode, $responseData, $result] = ResponseHelper::normalize($result);
        } catch (\Throwable $e) {
            $error = [
                'class' => $e::class,
                'message' => $e->getMessage(),
                'code' => $e->getCode(),
                'trace' => mb_strcut($e->getTraceAsString(), 0, 2000),
            ];

            $responseCode = ResponseHelper::extractStatusCode($e);

            if ($serveStale) {
                $result = $this->cache->serveStale($endpoint, $method, $encodedRequestData);
            }

            if ($result === null) {
                $this->integration->recordFailure();
                $durationMs = (int) ((microtime(true) - $startTime) * 1_000);

                $request = $this->persistRequest(
                    $endpoint, $method, $encodedRequestData, $retryOfId,
                    $relatedTo, $responseCode, $responseData, false,
                    $error, $durationMs, $cacheFor,
                );
                $this->lastCreatedRequestId = is_int($request->getKey()) ? $request->getKey() : null;

                RequestFailed::dispatch($this->integration, $request);

                throw $e;
            }
        }

        $durationMs = (int) ((microtime(true) - $startTime) * 1_000);

        $request = $this->persistRequest(
            $endpoint, $method, $encodedRequestData, $retryOfId,
            $relatedTo, $responseCode, $responseData, $responseSuccess,
            $error, $durationMs, $cacheFor,
        );
        $this->lastCreatedRequestId = is_int($request->getKey()) ? $request->getKey() : null;

        if ($responseSuccess) {
            RequestCompleted::dispatch($this->integration, $request);
            $this->integration->recordSuccess();
        } else {
            RequestFailed::dispatch($this->integration, $request);
            $this->integration->recordFailure();
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>|null  $error
     */
    private function persistRequest(
        string $endpoint,
        string $method,
        ?string $requestData,
        ?int $retryOfId,
        ?Model $relatedTo,
        ?int $responseCode,
        ?string $responseData,
        bool $responseSuccess,
        ?array $error,
        int $durationMs,
        ?CarbonInterface $cacheFor,
    ): IntegrationRequest {
        $provider = $this->integration->provider();

        if ($provider instanceof RedactsRequestData) {
            if ($requestData !== null) {
                $requestData = Redactor::redact($requestData, $provider->sensitiveRequestFields());
            }
            if ($responseData !== null) {
                $responseData = Redactor::redact($responseData, $provider->sensitiveResponseFields());
            }
        }

        $truncatedRequestData = $requestData !== null ? mb_strcut($requestData, 0, 65530) : null;

        /** @var IntegrationRequest */
        $request = $this->integration->requests()->create([
            'endpoint' => $endpoint,
            'method' => $method,
            'request_data' => $truncatedRequestData,
            'request_data_hash' => $truncatedRequestData !== null ? hash('xxh128', $truncatedRequestData) : null,
            'retry_of' => $retryOfId,
            'related_type' => $relatedTo !== null ? $relatedTo->getMorphClass() : null,
            'related_id' => $relatedTo !== null ? self::keyToString($relatedTo->getKey()) : null,
            'response_code' => $responseCode,
            'response_data' => $responseData,
            'response_success' => $responseSuccess,
            'error' => $error,
            'duration_ms' => $durationMs,
            'expires_at' => $cacheFor,
        ]);

        $this->integration->trackSyncRequestId($request->id);

        return $request;
    }

    /**
     * Attempt to serve a stale cached response; rethrow the original exception if unavailable.
     *
     * @throws \Throwable
     */
    private function serveStaleOrRethrow(
        \Throwable $e,
        bool $tryStale,
        string $endpoint,
        string $method,
        ?string $encodedRequestData,
    ): mixed {
        if ($tryStale) {
            $stale = $this->cache->serveStale($endpoint, $method, $encodedRequestData);
            if ($stale !== null) {
                return $stale;
            }
        }

        throw $e;
    }

    private function enforceRateLimit(): void
    {
        $provider = $this->integration->provider();

        $limit = null;
        if ($provider instanceof HasScheduledSync) {
            $limit = $provider->defaultRateLimit();
        }

        if ($limit === null) {
            return;
        }

        $maxWait = Config::rateLimitMaxWaitSeconds();
        $waited = 0;

        while (true) {
            $requestsThisMinute = $this->integration->requests()
                ->where('created_at', '>=', now()->subMinute())
                ->count();

            if ($requestsThisMinute < $limit) {
                return;
            }

            if ($waited >= $maxWait) {
                throw new RateLimitExceededException($this->integration, $requestsThisMinute, $limit);
            }

            sleep(1);
            $waited++;
        }
    }

    private static function keyToString(mixed $key): string
    {
        if (is_int($key) || is_string($key)) {
            return (string) $key;
        }

        throw new InvalidArgumentException('Model key must be a string or integer.');
    }
}
