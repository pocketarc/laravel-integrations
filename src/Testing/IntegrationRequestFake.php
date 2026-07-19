<?php

declare(strict_types=1);

namespace Integrations\Testing;

use Closure;
use Integrations\Models\Integration;
use Integrations\Support\EndpointPattern;
use PHPUnit\Framework\Assert;
use Throwable;

class IntegrationRequestFake
{
    private static ?self $instance = null;

    /** @var list<array{integration_id: int, endpoint: string, method: string, request_data: string|null}> */
    private array $recorded = [];

    /** @var array<string, mixed> */
    private array $fakeResponses;

    /** @var array<int, array<string, mixed>> */
    private array $scopedResponses = [];

    /** @var list<string> */
    private array $passthroughProviders = [];

    private bool $recordPassthrough = false;

    /**
     * @param  array<string, mixed>  $fakeResponses
     */
    public function __construct(array $fakeResponses = [])
    {
        $this->fakeResponses = $fakeResponses;
    }

    /**
     * @param  array<string, mixed>  $fakeResponses
     */
    public static function activate(array $fakeResponses = []): self
    {
        self::$instance = new self($fakeResponses);

        return self::$instance;
    }

    public static function deactivate(): void
    {
        self::$instance = null;
    }

    public static function active(): ?self
    {
        return self::$instance;
    }

    /**
     * @param  array<string, mixed>  $responses
     */
    public function forIntegration(Integration|int $integration, array $responses): self
    {
        if ($integration instanceof Integration) {
            if (! $integration->exists) {
                throw new \InvalidArgumentException('Cannot scope fakes to an unsaved Integration (id is null).');
            }

            $id = $integration->id;
        } else {
            $id = $integration;
        }

        $this->scopedResponses[$id] = array_merge($this->scopedResponses[$id] ?? [], $responses);

        return $this;
    }

    /**
     * Let requests for the given provider(s) fall through to the real request
     * executor instead of being served from this fake.
     *
     * Use this when a provider is exercised through a different faking layer —
     * e.g. ai-workflow routes OpenRouter calls through Integration::request(),
     * but the AI response is faked at the Prism layer. The OpenRouter request
     * must reach the real executor (and thus Prism's fake) rather than this
     * fake's response map, where it would otherwise go unmatched.
     */
    public function passthrough(string ...$providers): self
    {
        $this->passthroughProviders = array_values(array_unique([...$this->passthroughProviders, ...$providers]));

        return $this;
    }

    /**
     * Whether requests for the given provider should bypass this fake and run
     * against the real executor. See {@see passthrough()}.
     */
    public function shouldPassthrough(string $provider): bool
    {
        return in_array($provider, $this->passthroughProviders, true);
    }

    /**
     * Also log passthrough requests so they show up in {@see assertRequested()}
     * and the other assertion helpers, even though they ran against the real
     * executor rather than being served from this fake.
     *
     * Off by default: a passthrough request went to the real executor, not this
     * fake, so the fake's assertions don't see it unless you opt in here. The
     * real executor still writes its own integration_requests row either way;
     * this flag only controls the in-memory log the assertions read.
     */
    public function recordPassthrough(bool $record = true): self
    {
        $this->recordPassthrough = $record;

        return $this;
    }

    /**
     * Log a passthrough request when {@see recordPassthrough()} is enabled.
     * Called on the passthrough branch of {@see Integration::request()} before
     * the request reaches the real executor; a no-op otherwise.
     */
    public function notePassthrough(Integration $integration, string $endpoint, string $method, ?string $requestData): void
    {
        if (! $this->recordPassthrough || ! $this->shouldPassthrough($integration->provider)) {
            return;
        }

        $this->track($integration, $endpoint, $method, $requestData);
    }

    public function record(Integration $integration, string $endpoint, string $method, ?string $requestData, ?string $responseClass = null): mixed
    {
        $this->track($integration, $endpoint, $method, $requestData);

        $response = $this->findResponse($endpoint, $method, $integration->id);

        if ($response === null) {
            return null;
        }

        if ($response instanceof Throwable) {
            throw $response;
        }

        $raw = $response instanceof ResponseSequence
            ? $response->next()
            : ($response instanceof Closure ? $response() : $response);

        if ($raw !== null && $responseClass !== null) {
            return $responseClass::from($raw);
        }

        return $raw;
    }

    /**
     * Append a request to the in-memory log read by the assertion helpers.
     */
    private function track(Integration $integration, string $endpoint, string $method, ?string $requestData): void
    {
        $this->recorded[] = [
            'integration_id' => $integration->id,
            'endpoint' => $endpoint,
            'method' => $method,
            'request_data' => $requestData,
        ];
    }

    /**
     * @return list<array{integration_id: int, endpoint: string, method: string, request_data: string|null}>
     */
    public function recorded(): array
    {
        return $this->recorded;
    }

    public static function assertRequested(string $endpoint, ?int $times = null, ?string $method = null, ?int $integrationId = null): void
    {
        Assert::assertNotNull(self::$instance, 'IntegrationRequest::fake() was not called.');

        [$endpoint, $method] = self::normalizeAssertionTarget($endpoint, $method);

        $matches = array_filter(
            self::$instance->recorded,
            static fn (array $r): bool => self::matchesFilter($r, $endpoint, $method, $integrationId),
        );

        $count = count($matches);

        if ($times !== null) {
            Assert::assertSame(
                $times,
                $count,
                "Expected endpoint '{$endpoint}' to be requested {$times} time(s), but was requested {$count} time(s).",
            );
        } else {
            Assert::assertGreaterThan(
                0,
                $count,
                "Expected endpoint '{$endpoint}' to be requested at least once, but it was not.",
            );
        }
    }

    public static function assertNotRequested(string $endpoint, ?string $method = null, ?int $integrationId = null): void
    {
        Assert::assertNotNull(self::$instance, 'IntegrationRequest::fake() was not called.');

        [$endpoint, $method] = self::normalizeAssertionTarget($endpoint, $method);

        $matches = array_filter(
            self::$instance->recorded,
            static fn (array $r): bool => self::matchesFilter($r, $endpoint, $method, $integrationId),
        );

        Assert::assertCount(
            0,
            $matches,
            "Expected endpoint '{$endpoint}' to not be requested, but it was requested ".count($matches).' time(s).',
        );
    }

    /**
     * @param  Closure(string|null): bool  $callback
     */
    public static function assertRequestedWith(string $endpoint, Closure $callback, ?string $method = null, ?int $integrationId = null): void
    {
        Assert::assertNotNull(self::$instance, 'IntegrationRequest::fake() was not called.');

        [$endpoint, $method] = self::normalizeAssertionTarget($endpoint, $method);

        $matches = array_filter(
            self::$instance->recorded,
            static fn (array $r): bool => self::matchesFilter($r, $endpoint, $method, $integrationId),
        );

        Assert::assertNotEmpty($matches, "Expected endpoint '{$endpoint}' to be requested, but it was not.");

        foreach ($matches as $match) {
            if ($callback($match['request_data'])) {
                return;
            }
        }

        Assert::fail("Endpoint '{$endpoint}' was requested, but no request matched the given callback.");
    }

    public static function assertRequestCount(int $expected): void
    {
        Assert::assertNotNull(self::$instance, 'IntegrationRequest::fake() was not called.');

        $count = count(self::$instance->recorded);

        Assert::assertSame(
            $expected,
            $count,
            "Expected {$expected} request(s) total, but {$count} were recorded.",
        );
    }

    public static function assertNothingRequested(): void
    {
        Assert::assertNotNull(self::$instance, 'IntegrationRequest::fake() was not called.');

        Assert::assertCount(
            0,
            self::$instance->recorded,
            'Expected no requests, but '.count(self::$instance->recorded).' were recorded.',
        );
    }

    private function findResponse(string $endpoint, string $method, int $integrationId): mixed
    {
        if (array_key_exists($integrationId, $this->scopedResponses)) {
            $pool = $this->scopedResponses[$integrationId];
            $key = $this->matchInPool($endpoint, $method, $pool);

            if ($key !== false && array_key_exists($key, $pool)) {
                return $pool[$key];
            }
        }

        $key = $this->matchInPool($endpoint, $method, $this->fakeResponses);

        if ($key !== false && array_key_exists($key, $this->fakeResponses)) {
            return $this->fakeResponses[$key];
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $pool
     */
    private function matchInPool(string $endpoint, string $method, array $pool): string|false
    {
        $methodUpper = mb_strtoupper($method);
        $best = false;
        $bestPriority = 0;
        $bestSpecificity = 0;

        foreach (array_keys($pool) as $key) {
            [$priority, $specificity] = self::scoreMatch($key, $endpoint, $methodUpper);

            if ($priority === 0) {
                continue;
            }

            if ($priority > $bestPriority || ($priority === $bestPriority && $specificity > $bestSpecificity)) {
                $best = $key;
                $bestPriority = $priority;
                $bestSpecificity = $specificity;
            }
        }

        return $best;
    }

    /**
     * Score a fake response key against a request endpoint and method.
     *
     * Priority: 4 = method+exact, 3 = method+wildcard, 2 = any+exact, 1 = any+wildcard, 0 = no match.
     * Specificity (for wildcards): more path segments and fewer wildcards = higher score.
     *
     * @return array{int, int}
     */
    private static function scoreMatch(string $key, string $endpoint, string $method): array
    {
        [$keyMethod, $pattern] = self::parseKey($key);

        if ($keyMethod !== null && $keyMethod !== $method) {
            return [0, 0];
        }

        $isExact = $pattern === $endpoint;
        $isWildcard = ! $isExact && str_contains($pattern, '*') && self::matchesPattern($pattern, $endpoint);

        if (! $isExact && ! $isWildcard) {
            return [0, 0];
        }

        $hasMethod = $keyMethod !== null;
        $priority = match (true) {
            $hasMethod && $isExact => 4,
            $hasMethod => 3,
            $isExact => 2,
            default => 1,
        };

        $specificity = $isWildcard
            ? mb_substr_count($pattern, '/') * 100 - mb_substr_count($pattern, '*')
            : PHP_INT_MAX;

        return [$priority, $specificity];
    }

    /**
     * @return array{string|null, string}
     */
    private static function parseKey(string $key): array
    {
        return EndpointPattern::parse($key);
    }

    /**
     * Extract any `METHOD:` prefix from an assertion's endpoint argument and
     * reconcile it with an explicit `$method` filter. A conflict between the
     * two is surfaced loudly so the mistake is obvious at the call site.
     *
     * @return array{string, string|null}
     */
    private static function normalizeAssertionTarget(string $endpoint, ?string $method): array
    {
        [$prefixMethod, $parsedEndpoint] = self::parseKey($endpoint);

        if ($prefixMethod !== null && $method !== null && $prefixMethod !== mb_strtoupper($method)) {
            throw new \InvalidArgumentException(
                "Method prefix '{$prefixMethod}' in endpoint '{$endpoint}' conflicts with explicit method argument '{$method}'."
            );
        }

        return [$parsedEndpoint, $method ?? $prefixMethod];
    }

    /**
     * Match an endpoint against a pattern where only `*` is treated as a wildcard (matching any character except `/`).
     */
    private static function matchesPattern(string $pattern, string $endpoint): bool
    {
        return EndpointPattern::matches($pattern, $endpoint);
    }

    /**
     * @param  array{integration_id: int, endpoint: string, method: string, request_data: string|null}  $record
     */
    private static function matchesFilter(array $record, string $endpoint, ?string $method, ?int $integrationId): bool
    {
        if ($integrationId !== null && $record['integration_id'] !== $integrationId) {
            return false;
        }

        if ($method !== null && mb_strtoupper($record['method']) !== mb_strtoupper($method)) {
            return false;
        }

        return $record['endpoint'] === $endpoint || self::matchesPattern($endpoint, $record['endpoint']);
    }
}
