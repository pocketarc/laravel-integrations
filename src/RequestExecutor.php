<?php

declare(strict_types=1);

namespace Integrations;

use Carbon\CarbonInterface;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
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
use Spatie\LaravelData\Data;

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
     * @template TResponse of Data
     *
     * @param  class-string<TResponse>|null  $responseClass
     * @param  Closure(): mixed  $callback
     */
    public function execute(
        string $endpoint,
        string $method,
        ?string $responseClass,
        Closure $callback,
        ?Model $relatedTo,
        ?string $encodedRequestData,
        ?CarbonInterface $cacheFor,
        bool $serveStale,
        ?int $retryOfId,
        int $maxAttempts,
    ): mixed {
        $encodedRequestData = $this->redactRequestData($encodedRequestData);

        if ($cacheFor !== null) {
            $cached = $this->cache->serve($endpoint, $method, $encodedRequestData, $responseClass);
            if ($cached !== null) {
                return $cached;
            }
        }

        $this->enforceRateLimit();

        if ($maxAttempts > 1) {
            return $this->requestWithRetries(
                $endpoint, $method, $responseClass, $callback, $relatedTo,
                $encodedRequestData, $cacheFor, $serveStale, $maxAttempts, $retryOfId,
            );
        }

        return $this->executeRequest(
            $endpoint, $method, $responseClass, $callback, $relatedTo,
            $encodedRequestData, $cacheFor, $serveStale, $retryOfId,
        );
    }

    /**
     * @template TResponse of Data
     *
     * @param  class-string<TResponse>|null  $responseClass
     * @param  Closure(): mixed  $callback
     */
    private function requestWithRetries(
        string $endpoint,
        string $method,
        ?string $responseClass,
        Closure $callback,
        ?Model $relatedTo,
        ?string $encodedRequestData,
        ?CarbonInterface $cacheFor,
        bool $serveStale,
        int $maxAttempts,
        ?int $retryOfId = null,
    ): mixed {
        $firstRequestId = $retryOfId;

        $this->lastCreatedRequestId = null;

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            $isLastAttempt = $attempt >= $maxAttempts;
            $allowStale = $serveStale && $isLastAttempt;

            try {
                $result = $this->executeRequest(
                    $endpoint, $method, $responseClass, $callback, $relatedTo,
                    $encodedRequestData, $cacheFor, $allowStale,
                    retryOfId: $firstRequestId,
                );

                $firstRequestId ??= $this->lastCreatedRequestId;

                return $result;
            } catch (\Throwable $e) {
                $firstRequestId ??= $this->lastCreatedRequestId;

                if (! RetryHandler::isRetryable($e) || $isLastAttempt) {
                    return $this->serveStaleOrRethrow($e, $serveStale && ! $allowStale, $endpoint, $method, $encodedRequestData, $responseClass);
                }

                usleep(RetryHandler::calculateDelayMs($e, $attempt) * 1000);
                $this->enforceRateLimit();
            }
        }

        throw new \RuntimeException('Retry logic exhausted without result.');
    }

    /**
     * @template TResponse of Data
     *
     * @param  class-string<TResponse>|null  $responseClass
     * @param  Closure(): mixed  $callback
     */
    private function executeRequest(
        string $endpoint,
        string $method,
        ?string $responseClass,
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
            $raw = $callback();

            [$responseCode, $responseData, $parsed] = ResponseHelper::normalize($raw);
            $result = $this->convertResponse($parsed, $responseClass, $endpoint, $cacheFor);
            $responseSuccess = true;
        } catch (\Throwable $e) {
            $error = [
                'class' => $e::class,
                'message' => $e->getMessage(),
                'code' => $e->getCode(),
                'trace' => mb_strcut($e->getTraceAsString(), 0, 2000),
            ];

            $responseCode = ResponseHelper::extractStatusCode($e);

            if ($serveStale) {
                $result = $this->cache->serveStale($endpoint, $method, $encodedRequestData, $responseClass);
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

        if ($provider instanceof RedactsRequestData && $responseData !== null) {
            $responseData = Redactor::redact($responseData, $provider->sensitiveResponseFields());
        }

        $truncatedRequestData = $requestData !== null ? mb_strcut($requestData, 0, 65530) : null;

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
     * @template TResponse of Data
     *
     * @param  class-string<TResponse>|null  $responseClass
     *
     * @throws \Throwable
     */
    private function serveStaleOrRethrow(
        \Throwable $e,
        bool $tryStale,
        string $endpoint,
        string $method,
        ?string $encodedRequestData,
        ?string $responseClass,
    ): mixed {
        if ($tryStale) {
            $stale = $this->cache->serveStale($endpoint, $method, $encodedRequestData, $responseClass);
            if ($stale !== null) {
                return $stale;
            }
        }

        throw $e;
    }

    private function convertResponse(mixed $parsed, ?string $responseClass, string $endpoint, ?CarbonInterface $cacheFor): mixed
    {
        if ($responseClass !== null && (is_array($parsed) || is_object($parsed))) {
            return $responseClass::from($parsed);
        }

        if ($cacheFor !== null && $responseClass === null && is_object($parsed)) {
            Log::warning("Caching response for '{$endpoint}' without a responseClass — cached responses will be returned as arrays, not ".get_class($parsed).'. Use requestAs() or toAs() for type-consistent caching.');
        }

        return $parsed;
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
            $now = now();
            $currentKey = $this->rateLimitKey($now);
            $previousKey = $this->rateLimitKey($now->copy()->subMinute());

            $elapsedFraction = ((int) $now->format('s') + (int) $now->format('u') / 1_000_000) / 60.0;

            Cache::add($currentKey, 0, 120);
            Cache::add($previousKey, 0, 120);

            $rawCurrent = Cache::get($currentKey, 0);
            $rawPrevious = Cache::get($previousKey, 0);
            $currentCount = is_numeric($rawCurrent) ? (int) $rawCurrent : 0;
            $previousCount = is_numeric($rawPrevious) ? (int) $rawPrevious : 0;

            $estimate = (int) ceil($currentCount + $previousCount * (1.0 - $elapsedFraction));

            if ($estimate < $limit) {
                Cache::increment($currentKey);

                return;
            }

            if ($waited >= $maxWait) {
                throw new RateLimitExceededException($this->integration, $estimate, $limit);
            }

            sleep(1);
            $waited++;
        }
    }

    private function rateLimitKey(CarbonInterface $time): string
    {
        return Config::cachePrefix().':rate:'.$this->integration->id.':'.$time->format('Y-m-d-H-i');
    }

    private function redactRequestData(?string $requestData): ?string
    {
        if ($requestData === null) {
            return null;
        }

        $provider = $this->integration->provider();

        if ($provider instanceof RedactsRequestData) {
            return Redactor::redact($requestData, $provider->sensitiveRequestFields());
        }

        return $requestData;
    }

    private static function keyToString(mixed $key): string
    {
        if (is_int($key) || is_string($key)) {
            return (string) $key;
        }

        throw new InvalidArgumentException('Model key must be a string or integer.');
    }
}
