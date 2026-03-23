<?php

declare(strict_types=1);

namespace Integrations\Testing;

use Closure;
use Integrations\Models\Integration;
use PHPUnit\Framework\Assert;

class IntegrationRequestFake
{
    private static ?self $instance = null;

    /** @var list<array{integration_id: int, endpoint: string, method: string, request_data: string|null}> */
    private array $recorded = [];

    /** @var array<string, mixed|Closure> */
    private array $fakeResponses;

    /**
     * @param  array<string, mixed|Closure>  $fakeResponses
     */
    public function __construct(array $fakeResponses = [])
    {
        $this->fakeResponses = $fakeResponses;
    }

    /**
     * @param  array<string, mixed|Closure>  $fakeResponses
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

    public function record(Integration $integration, string $endpoint, string $method, ?string $requestData): mixed
    {
        $this->recorded[] = [
            'integration_id' => $integration->id,
            'endpoint' => $endpoint,
            'method' => $method,
            'request_data' => $requestData,
        ];

        if (isset($this->fakeResponses[$endpoint])) {
            $response = $this->fakeResponses[$endpoint];

            return $response instanceof Closure ? $response() : $response;
        }

        return null;
    }

    /**
     * @return list<array{integration_id: int, endpoint: string, method: string, request_data: string|null}>
     */
    public function recorded(): array
    {
        return $this->recorded;
    }

    public static function assertRequested(string $endpoint, ?int $times = null): void
    {
        Assert::assertNotNull(self::$instance, 'IntegrationRequest::fake() was not called.');

        $matches = array_filter(
            self::$instance->recorded,
            fn (array $r): bool => $r['endpoint'] === $endpoint,
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

    public static function assertNotRequested(string $endpoint): void
    {
        Assert::assertNotNull(self::$instance, 'IntegrationRequest::fake() was not called.');

        $matches = array_filter(
            self::$instance->recorded,
            fn (array $r): bool => $r['endpoint'] === $endpoint,
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
    public static function assertRequestedWith(string $endpoint, Closure $callback): void
    {
        Assert::assertNotNull(self::$instance, 'IntegrationRequest::fake() was not called.');

        $matches = array_filter(
            self::$instance->recorded,
            fn (array $r): bool => $r['endpoint'] === $endpoint,
        );

        Assert::assertNotEmpty($matches, "Expected endpoint '{$endpoint}' to be requested, but it was not.");

        foreach ($matches as $match) {
            if ($callback($match['request_data'])) {
                return;
            }
        }

        Assert::fail("Endpoint '{$endpoint}' was requested, but no request matched the given callback.");
    }
}
