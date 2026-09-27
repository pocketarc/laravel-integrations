<?php

declare(strict_types=1);

namespace Integrations;

use Illuminate\Database\Eloquent\Builder;
use Integrations\Exceptions\SchemaDriftException;
use Integrations\Models\Integration;
use Integrations\Models\IntegrationRequest;
use Integrations\Support\BinaryGuard;
use JsonException;
use Spatie\LaravelData\Data;
use Throwable;

use function Safe\json_decode;

final class RequestCache
{
    public function __construct(
        private readonly Integration $integration,
    ) {}

    /**
     * Serve a valid (non-expired) cached response, or null on cache miss.
     *
     * @template TResponse of Data
     *
     * @param  class-string<TResponse>|null  $responseClass
     */
    public function serve(string $endpoint, string $method, ?string $requestData, ?string $responseClass = null): mixed
    {
        $cached = $this->findCached($endpoint, $method, $requestData);

        return $cached !== null ? $this->decode($cached, 'cache_hits', $responseClass) : null;
    }

    /**
     * Serve a stale cached response (ignoring expiry), or null on miss.
     *
     * @template TResponse of Data
     *
     * @param  class-string<TResponse>|null  $responseClass
     */
    public function serveStale(string $endpoint, string $method, ?string $requestData, ?string $responseClass = null): mixed
    {
        $stale = $this->findStale($endpoint, $method, $requestData);

        return $stale !== null ? $this->decode($stale, 'stale_hits', $responseClass) : null;
    }

    private function findCached(string $endpoint, string $method, ?string $requestData): ?IntegrationRequest
    {
        $hash = self::requestDataHash($requestData);

        return $this->integration->requests()
            ->where('endpoint', $endpoint)
            ->where('method', $method)
            ->where('response_success', true)
            ->where('expires_at', '>', now())
            ->when($hash !== null, fn (Builder $q) => $q->where('request_data_hash', $hash))
            ->when($hash === null, fn (Builder $q) => $q->whereNull('request_data'))
            ->latest()
            ->first();
    }

    private function findStale(string $endpoint, string $method, ?string $requestData): ?IntegrationRequest
    {
        $hash = self::requestDataHash($requestData);

        return $this->integration->requests()
            ->where('endpoint', $endpoint)
            ->where('method', $method)
            ->where('response_success', true)
            ->whereNotNull('expires_at')
            ->when($hash !== null, fn (Builder $q) => $q->where('request_data_hash', $hash))
            ->when($hash === null, fn (Builder $q) => $q->whereNull('request_data'))
            ->latest()
            ->first();
    }

    /**
     * Return the request data in the form stored in the `request_data` column.
     */
    public static function storedRequestData(?string $requestData): ?string
    {
        $sanitized = BinaryGuard::sanitize($requestData);

        return $sanitized !== null ? mb_strcut($sanitized, 0, 65530) : null;
    }

    /**
     * Hash the whole sanitised body. `storedRequestData()` truncates it to
     * 65530 bytes, and two bodies that differ only after that point must
     * have different cache keys.
     */
    public static function requestDataHash(?string $requestData): ?string
    {
        $sanitized = BinaryGuard::sanitize($requestData);

        return $sanitized !== null ? hash('xxh128', $sanitized) : null;
    }

    /**
     * @template TResponse of Data
     *
     * @param  class-string<TResponse>|null  $responseClass
     */
    private function decode(IntegrationRequest $cached, string $hitColumn, ?string $responseClass = null): mixed
    {
        try {
            $decoded = json_decode($cached->response_data ?? '{}', true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        $cached->increment($hitColumn);

        if ($responseClass === null) {
            return $decoded;
        }

        try {
            return $responseClass::from($decoded);
        } catch (Throwable $e) {
            throw new SchemaDriftException(
                integration: $this->integration,
                responseClass: $responseClass,
                parsedData: $decoded,
                source: 'cache',
                previous: $e,
            );
        }
    }
}
