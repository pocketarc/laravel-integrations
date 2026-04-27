<?php

declare(strict_types=1);

namespace Integrations;

use Carbon\CarbonInterface;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Integrations\Concerns\HandlesPendingRequest;
use Integrations\Models\Integration;
use Spatie\LaravelData\Data;

/**
 * Typed fluent request builder. Returned by {@see PendingRequest::as()}; you
 * don't construct this directly. The terminal verbs (`get()`, `post()`, etc.)
 * return an instance of `T` because the executor hydrates the closure's
 * result through `T::from()`.
 *
 * The class is templated on `T` (a Spatie Data subclass) and the template is
 * bound at construction time, so PHPStan can flow it through any subsequent
 * modifier call (which all return `static`) and out through the terminal
 * verb's `@return T`. The modifier methods themselves live on the
 * `HandlesPendingRequest` trait shared with `PendingRequest`.
 *
 * @template T of Data
 */
class TypedPendingRequest
{
    use HandlesPendingRequest;

    /**
     * @param  class-string<T>  $responseClass
     * @param  string|array<string, mixed>|null  $requestData
     */
    public function __construct(
        Integration $integration,
        string $endpoint,
        private readonly string $responseClass,
        ?Model $relatedTo = null,
        string|array|null $requestData = null,
        ?CarbonInterface $cacheFor = null,
        bool $serveStale = false,
        ?int $retryOfId = null,
        ?int $maxAttempts = null,
        ?string $idempotencyKey = null,
        bool $idempotencyKeyRequested = false,
    ) {
        $this->integration = $integration;
        $this->endpoint = $endpoint;
        $this->relatedTo = $relatedTo;
        $this->requestData = $requestData;
        $this->cacheFor = $cacheFor;
        $this->serveStale = $serveStale;
        $this->retryOfId = $retryOfId;
        $this->maxAttempts = $maxAttempts;
        $this->idempotencyKey = $idempotencyKey;
        $this->idempotencyKeyRequested = $idempotencyKeyRequested;
    }

    /**
     * @param  Closure(RequestContext=): mixed  $callback
     * @return T
     *
     * @param-immediately-invoked-callable $callback
     */
    public function get(Closure $callback): mixed
    {
        return $this->typed('GET', $callback);
    }

    /**
     * @param  Closure(RequestContext=): mixed  $callback
     * @return T
     *
     * @param-immediately-invoked-callable $callback
     */
    public function post(Closure $callback): mixed
    {
        return $this->typed('POST', $callback);
    }

    /**
     * @param  Closure(RequestContext=): mixed  $callback
     * @return T
     *
     * @param-immediately-invoked-callable $callback
     */
    public function put(Closure $callback): mixed
    {
        return $this->typed('PUT', $callback);
    }

    /**
     * @param  Closure(RequestContext=): mixed  $callback
     * @return T
     *
     * @param-immediately-invoked-callable $callback
     */
    public function patch(Closure $callback): mixed
    {
        return $this->typed('PATCH', $callback);
    }

    /**
     * @param  Closure(RequestContext=): mixed  $callback
     * @return T
     *
     * @param-immediately-invoked-callable $callback
     */
    public function delete(Closure $callback): mixed
    {
        return $this->typed('DELETE', $callback);
    }

    /**
     * @param  Closure(RequestContext=): mixed  $callback
     * @return T
     *
     * @param-immediately-invoked-callable $callback
     */
    public function execute(string $method, Closure $callback): mixed
    {
        return $this->typed($method, $callback);
    }

    /**
     * Single funnel for the terminal verbs. The result is hydrated through
     * `T::from()` inside `Integration::request()`, so it's safe to assert
     * the typed return here.
     *
     * @param  Closure(RequestContext=): mixed  $callback
     * @return T
     *
     * @param-immediately-invoked-callable $callback
     */
    private function typed(string $method, Closure $callback): mixed
    {
        /** @var T $result */
        $result = $this->dispatch($method, $callback, $this->responseClass);

        return $result;
    }
}
