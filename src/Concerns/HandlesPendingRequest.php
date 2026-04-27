<?php

declare(strict_types=1);

namespace Integrations\Concerns;

use Carbon\CarbonInterface;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Integrations\Models\Integration;
use Integrations\RequestContext;
use InvalidArgumentException;
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

    protected bool $idempotencyKeyRequested = false;

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
     * Tag the request with an idempotency key so the provider (if it
     * supports them) deduplicates duplicate calls. The textbook example
     * is a user double-clicking "Pay" across two tabs and submitting
     * the same charge twice. Pass a deterministic key like
     * `"order-{$id}"` for that case.
     *
     * Calling without an argument (or with null) auto-generates a UUID
     * at execute time. That only protects core's own retry attempts:
     * useful but narrower. Empty string throws, since blank silently
     * disables Stripe's dedup and similar.
     *
     * Providers that don't implement `SupportsIdempotency` still see the
     * key persisted on the `integration_requests.idempotency_key` column
     * for searchability, but core logs a warning when a key is set
     * against a non-supporting provider, since provider-side dedup
     * won't fire.
     */
    public function withIdempotencyKey(?string $key = null): static
    {
        if ($key === '') {
            throw new InvalidArgumentException('Idempotency key must not be empty when provided.');
        }

        $this->idempotencyKey = $key;
        $this->idempotencyKeyRequested = true;

        return $this;
    }

    /**
     * Forward the assembled request through `Integration::request()`. The
     * `$responseClass` argument is null for the untyped builder and the
     * bound `class-string<T>` for the typed builder.
     *
     * @param  Closure(RequestContext=): mixed  $callback
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
            idempotencyKey: $this->resolveIdempotencyKey(),
        );
    }

    /**
     * Resolve the idempotency key right before the request fires. A null
     * key with `idempotencyKeyRequested = true` (i.e. the caller said
     * `withIdempotencyKey()` with no args) becomes a fresh UUID. A null
     * key with `requested = false` (the caller never opted in) stays
     * null, signalling "no key on this request".
     */
    private function resolveIdempotencyKey(): ?string
    {
        if (! $this->idempotencyKeyRequested) {
            return null;
        }

        return $this->idempotencyKey ?? (string) Str::uuid();
    }
}
