<?php

declare(strict_types=1);

namespace Integrations\Concerns;

use Carbon\CarbonInterface;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Integrations\Exceptions\IdempotencyConflict;
use Integrations\Models\Integration;
use Integrations\Models\IntegrationIdempotencyKey;
use Integrations\RequestContext;
use Spatie\LaravelData\Data;

/**
 * Shared state and modifier methods for the request builder. `PendingRequest`
 * (untyped) and `TypedPendingRequest<T>` (typed) both consume this so the
 * fluent chain reads identically across both, and the modifier methods return
 * `static` so the typed chain stays typed.
 *
 * The URL-string convenience for terminal verbs lives on `PendingRequest`
 * only (it's a raw-HTTP escape hatch); the typed builder always takes a
 * closure.
 */
trait HandlesPendingRequest
{
    protected Integration $integration;

    protected string $endpoint;

    protected ?Model $relatedTo = null;

    /** @var string|array<string, mixed>|null */
    protected string|array|null $requestData = null;

    protected ?CarbonInterface $cacheFor = null;

    protected bool $serveStale = false;

    protected ?int $retryOfId = null;

    protected ?int $maxAttempts = null;

    protected ?string $idempotencyKey = null;

    public function withCache(CarbonInterface|int $ttl, bool $serveStale = false): static
    {
        $this->cacheFor = is_int($ttl) ? now()->addSeconds($ttl) : $ttl;
        $this->serveStale = $serveStale;

        return $this;
    }

    public function withAttempts(int $max): static
    {
        $this->maxAttempts = $max;

        return $this;
    }

    public function relatedTo(Model $model): static
    {
        $this->relatedTo = $model;

        return $this;
    }

    /**
     * @param  string|array<string, mixed>  $data
     */
    public function withData(string|array $data): static
    {
        $this->requestData = $data;

        return $this;
    }

    public function retryOf(int $id): static
    {
        $this->retryOfId = $id;

        return $this;
    }

    /**
     * Tag the request with an idempotency key for at-most-once
     * execution. Before the underlying call fires, core inserts a row
     * into `integration_idempotency_keys` so a second call with the
     * same `(integration_id, key)` throws {@see IdempotencyConflict}
     * and the closure is skipped. The key is also passed to the
     * provider in the `RequestContext` so adapters that implement
     * `SupportsIdempotency` can send it on the wire as a backstop
     * against intra-attempt SDK retries.
     *
     * The key must be application-meaningful and stable across retries
     * (e.g. `"charge:order-{$id}"`, `"send-receipt:{$orderId}"`).
     * Random per-call values defeat the purpose; if you don't have a
     * domain key, omit this call and accept that the work isn't
     * idempotent.
     *
     * Passing `null` is a no-op (no key, no row, no header). Empty
     * string throws, since it silently disables provider-side dedup
     * and would never roundtrip through the unique index correctly.
     */
    public function withIdempotencyKey(?string $key): static
    {
        if ($key === null) {
            return $this;
        }

        IntegrationIdempotencyKey::validateKey($key);

        $this->idempotencyKey = $key;

        return $this;
    }

    /**
     * Forward the assembled request through `Integration::request()`. The
     * `$responseClass` argument is null for the untyped builder and the
     * bound `class-string<T>` for the typed builder.
     *
     * @param  (Closure(): mixed)|(Closure(RequestContext): mixed)  $callback
     * @param  class-string<Data>|null  $responseClass
     *
     * @param-immediately-invoked-callable $callback
     */
    protected function dispatch(string $method, Closure $callback, ?string $responseClass): mixed
    {
        return $this->integration->request(
            endpoint: $this->endpoint,
            method: $method,
            callback: $callback,
            responseClass: $responseClass,
            relatedTo: $this->relatedTo,
            requestData: $this->requestData,
            cacheFor: $this->cacheFor,
            serveStale: $this->serveStale,
            retryOfId: $this->retryOfId,
            maxAttempts: $this->maxAttempts,
            idempotencyKey: $this->idempotencyKey,
        );
    }
}
