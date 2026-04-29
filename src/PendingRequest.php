<?php

declare(strict_types=1);

namespace Integrations;

use Closure;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Integrations\Concerns\HandlesPendingRequest;
use Integrations\Models\Integration;
use InvalidArgumentException;
use Spatie\LaravelData\Data;

/**
 * Untyped fluent request builder. Use `->as(SomeData::class)` to switch into
 * the typed builder ({@see TypedPendingRequest}) when you want a typed Data
 * instance back from the terminal verb. Otherwise, the verbs return whatever
 * the closure returns (or whatever Laravel's HTTP client returns when a URL
 * is passed instead of a closure).
 *
 * The URL-string convenience is only available here on the untyped builder,
 * because typed callers are always wrapping an SDK call (which they pass as
 * a closure).
 */
class PendingRequest
{
    use HandlesPendingRequest;

    public function __construct(Integration $integration, string $endpoint)
    {
        $this->integration = $integration;
        $this->endpoint = $endpoint;
    }

    /**
     * Switch into the typed builder. The executed callback's return value
     * will be hydrated through `$class::from()` and the typed instance comes
     * back from `->get()` / `->post()` / etc.
     *
     * @template T of Data
     *
     * @param  class-string<T>  $class
     * @return TypedPendingRequest<T>
     */
    public function as(string $class): TypedPendingRequest
    {
        if (! is_subclass_of($class, Data::class, true)) {
            throw new InvalidArgumentException(sprintf(
                '%s requires a class-string of %s, got %s.',
                __METHOD__,
                Data::class,
                $class,
            ));
        }

        return new TypedPendingRequest(
            integration: $this->integration,
            endpoint: $this->endpoint,
            responseClass: $class,
            relatedTo: $this->relatedTo,
            requestData: $this->requestData,
            cacheFor: $this->cacheFor,
            serveStale: $this->serveStale,
            retryOfId: $this->retryOfId,
            maxAttempts: $this->maxAttempts,
            idempotencyKey: $this->idempotencyKey,
            idempotencyKeyRequested: $this->idempotencyKeyRequested,
        );
    }

    /**
     * @param  (Closure(): mixed)|(Closure(RequestContext): mixed)|string  $callbackOrUrl
     *
     * @param-immediately-invoked-callable $callbackOrUrl
     */
    public function get(Closure|string $callbackOrUrl): mixed
    {
        return $this->execute('GET', $callbackOrUrl);
    }

    /**
     * @param  (Closure(): mixed)|(Closure(RequestContext): mixed)|string  $callbackOrUrl
     *
     * @param-immediately-invoked-callable $callbackOrUrl
     */
    public function post(Closure|string $callbackOrUrl): mixed
    {
        return $this->execute('POST', $callbackOrUrl);
    }

    /**
     * @param  (Closure(): mixed)|(Closure(RequestContext): mixed)|string  $callbackOrUrl
     *
     * @param-immediately-invoked-callable $callbackOrUrl
     */
    public function put(Closure|string $callbackOrUrl): mixed
    {
        return $this->execute('PUT', $callbackOrUrl);
    }

    /**
     * @param  (Closure(): mixed)|(Closure(RequestContext): mixed)|string  $callbackOrUrl
     *
     * @param-immediately-invoked-callable $callbackOrUrl
     */
    public function patch(Closure|string $callbackOrUrl): mixed
    {
        return $this->execute('PATCH', $callbackOrUrl);
    }

    /**
     * @param  (Closure(): mixed)|(Closure(RequestContext): mixed)|string  $callbackOrUrl
     *
     * @param-immediately-invoked-callable $callbackOrUrl
     */
    public function delete(Closure|string $callbackOrUrl): mixed
    {
        return $this->execute('DELETE', $callbackOrUrl);
    }

    /**
     * @param  (Closure(): mixed)|(Closure(RequestContext): mixed)|string  $callbackOrUrl
     *
     * @param-immediately-invoked-callable $callbackOrUrl
     */
    public function execute(string $method, Closure|string $callbackOrUrl): mixed
    {
        $callback = is_string($callbackOrUrl)
            ? $this->buildHttpCallback($method, $callbackOrUrl)
            : $callbackOrUrl;

        return $this->dispatch($method, $callback, null);
    }

    /**
     * @return Closure(): Response
     */
    private function buildHttpCallback(string $method, string $url): Closure
    {
        return function () use ($method, $url): Response {
            $response = match (true) {
                mb_strtoupper($method) === 'GET' => Http::get($url, is_array($this->requestData) ? $this->requestData : []),
                is_array($this->requestData) => Http::send($method, $url, ['json' => $this->requestData]),
                is_string($this->requestData) => Http::send($method, $url, ['body' => $this->requestData]),
                default => Http::send($method, $url),
            };

            $response->throw();

            return $response;
        };
    }
}
