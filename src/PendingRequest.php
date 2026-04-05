<?php

declare(strict_types=1);

namespace Integrations;

use Carbon\CarbonInterface;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Integrations\Models\Integration;
use Spatie\LaravelData\Data;

/**
 * @template TResponse of Data
 */
class PendingRequest
{
    private ?Model $relatedTo = null;

    /** @var string|array<string, mixed>|null */
    private string|array|null $requestData = null;

    private ?CarbonInterface $cacheFor = null;

    private bool $serveStale = false;

    private ?int $retryOfId = null;

    private ?int $maxAttempts = null;

    /**
     * @param  class-string<TResponse>|null  $responseClass
     */
    public function __construct(
        private readonly Integration $integration,
        private readonly string $endpoint,
        private readonly ?string $responseClass = null,
    ) {}

    /** @return self<TResponse> */
    public function withCache(CarbonInterface|int $ttl, bool $serveStale = false): self
    {
        $this->cacheFor = is_int($ttl) ? now()->addSeconds($ttl) : $ttl;
        $this->serveStale = $serveStale;

        return $this;
    }

    /** @return self<TResponse> */
    public function withAttempts(int $max): self
    {
        $this->maxAttempts = $max;

        return $this;
    }

    /** @return self<TResponse> */
    public function relatedTo(Model $model): self
    {
        $this->relatedTo = $model;

        return $this;
    }

    /**
     * @param  string|array<string, mixed>  $data
     * @return self<TResponse>
     */
    public function withData(string|array $data): self
    {
        $this->requestData = $data;

        return $this;
    }

    /** @return self<TResponse> */
    public function retryOf(int $id): self
    {
        $this->retryOfId = $id;

        return $this;
    }

    /**
     * @param  (Closure(): mixed)|string  $callbackOrUrl
     *
     * @param-immediately-invoked-callable $callbackOrUrl
     */
    public function get(Closure|string $callbackOrUrl): mixed
    {
        return $this->execute('GET', $callbackOrUrl);
    }

    /**
     * @param  (Closure(): mixed)|string  $callbackOrUrl
     *
     * @param-immediately-invoked-callable $callbackOrUrl
     */
    public function post(Closure|string $callbackOrUrl): mixed
    {
        return $this->execute('POST', $callbackOrUrl);
    }

    /**
     * @param  (Closure(): mixed)|string  $callbackOrUrl
     *
     * @param-immediately-invoked-callable $callbackOrUrl
     */
    public function put(Closure|string $callbackOrUrl): mixed
    {
        return $this->execute('PUT', $callbackOrUrl);
    }

    /**
     * @param  (Closure(): mixed)|string  $callbackOrUrl
     *
     * @param-immediately-invoked-callable $callbackOrUrl
     */
    public function patch(Closure|string $callbackOrUrl): mixed
    {
        return $this->execute('PATCH', $callbackOrUrl);
    }

    /**
     * @param  (Closure(): mixed)|string  $callbackOrUrl
     *
     * @param-immediately-invoked-callable $callbackOrUrl
     */
    public function delete(Closure|string $callbackOrUrl): mixed
    {
        return $this->execute('DELETE', $callbackOrUrl);
    }

    /**
     * @param  (Closure(): mixed)|string  $callbackOrUrl
     *
     * @param-immediately-invoked-callable $callbackOrUrl
     */
    public function execute(string $method, Closure|string $callbackOrUrl): mixed
    {
        $callback = is_string($callbackOrUrl)
            ? $this->buildHttpCallback($method, $callbackOrUrl)
            : $callbackOrUrl;

        if ($this->responseClass !== null) {
            return $this->integration->requestAs(
                endpoint: $this->endpoint,
                method: $method,
                responseClass: $this->responseClass,
                callback: $callback,
                relatedTo: $this->relatedTo,
                requestData: $this->requestData,
                cacheFor: $this->cacheFor,
                serveStale: $this->serveStale,
                retryOfId: $this->retryOfId,
                maxAttempts: $this->maxAttempts,
            );
        }

        return $this->integration->request(
            endpoint: $this->endpoint,
            method: $method,
            callback: $callback,
            relatedTo: $this->relatedTo,
            requestData: $this->requestData,
            cacheFor: $this->cacheFor,
            serveStale: $this->serveStale,
            retryOfId: $this->retryOfId,
            maxAttempts: $this->maxAttempts,
        );
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
