<?php

declare(strict_types=1);

namespace Integrations;

use Illuminate\Database\Eloquent\Builder;
use Integrations\Models\Integration;
use Integrations\Models\IntegrationRequest;

use function Safe\json_decode;

final class RequestCache
{
    public function __construct(
        private readonly Integration $integration,
    ) {}

    /**
     * Serve a valid (non-expired) cached response, or null on cache miss.
     */
    public function serve(string $endpoint, string $method, ?string $requestData): mixed
    {
        $cached = $this->findCached($endpoint, $method, $requestData);

        return $cached !== null ? $this->decode($cached, 'cache_hits') : null;
    }

    /**
     * Serve a stale cached response (ignoring expiry), or null on miss.
     */
    public function serveStale(string $endpoint, string $method, ?string $requestData): mixed
    {
        $stale = $this->findStale($endpoint, $method, $requestData);

        return $stale !== null ? $this->decode($stale, 'stale_hits') : null;
    }

    private function findCached(string $endpoint, string $method, ?string $requestData): ?IntegrationRequest
    {
        $hash = $requestData !== null ? hash('xxh128', mb_strcut($requestData, 0, 65530)) : null;

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
        $hash = $requestData !== null ? hash('xxh128', mb_strcut($requestData, 0, 65530)) : null;

        return $this->integration->requests()
            ->where('endpoint', $endpoint)
            ->where('method', $method)
            ->where('response_success', true)
            ->when($hash !== null, fn (Builder $q) => $q->where('request_data_hash', $hash))
            ->when($hash === null, fn (Builder $q) => $q->whereNull('request_data'))
            ->latest()
            ->first();
    }

    private function decode(IntegrationRequest $cached, string $hitColumn): mixed
    {
        try {
            $decoded = json_decode($cached->response_data ?? '{}', true, 512, JSON_THROW_ON_ERROR);
            $cached->increment($hitColumn);

            return $decoded;
        } catch (\JsonException) {
            return null;
        }
    }
}
