<?php

declare(strict_types=1);

namespace Integrations;

use Illuminate\Database\Eloquent\Builder;
use Integrations\Models\Integration;
use Integrations\Models\IntegrationRequest;
use Spatie\LaravelData\Data;

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
        $hash = $this->computeRequestHash($requestData);

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
        $hash = $this->computeRequestHash($requestData);

        return $this->integration->requests()
            ->where('endpoint', $endpoint)
            ->where('method', $method)
            ->where('response_success', true)
            ->when($hash !== null, fn (Builder $q) => $q->where('request_data_hash', $hash))
            ->when($hash === null, fn (Builder $q) => $q->whereNull('request_data'))
            ->latest()
            ->first();
    }

    private function computeRequestHash(?string $requestData): ?string
    {
        return $requestData !== null ? hash('xxh128', mb_strcut($requestData, 0, 65530)) : null;
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
            $cached->increment($hitColumn);

            return $responseClass !== null ? $responseClass::from($decoded) : $decoded;
        } catch (\JsonException) {
            return null;
        }
    }
}
