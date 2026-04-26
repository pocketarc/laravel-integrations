<?php

declare(strict_types=1);

namespace Integrations\Tests\Unit;

use Illuminate\Support\Facades\Cache;
use Integrations\Exceptions\RateLimitExceededException;
use Integrations\IntegrationManager;
use Integrations\Models\Integration;
use Integrations\RequestContext;
use Integrations\Tests\Fixtures\TestProvider;
use Integrations\Tests\TestCase;

class AdaptiveRateLimitTest extends TestCase
{
    private Integration $integration;

    protected function setUp(): void
    {
        parent::setUp();

        app(IntegrationManager::class)->register('test', TestProvider::class);
        $this->integration = Integration::create(['provider' => 'test', 'name' => 'Test']);
        $this->integration->refresh();

        // Don't sleep in tests; throw immediately if suppressed.
        config(['integrations.rate_limiting.max_wait_seconds' => 0]);
    }

    public function test_retry_after_seconds_suppresses_subsequent_requests(): void
    {
        $this->integration->at('/api/charge')->post(function (RequestContext $ctx): array {
            $ctx->reportResponseMetadata(retryAfterSeconds: 30);

            return ['ok' => true];
        });

        $this->expectException(RateLimitExceededException::class);

        $this->integration->at('/api/charge')->post(fn (): array => ['ok' => true]);
    }

    public function test_zero_remaining_with_reset_at_suppresses_subsequent_requests(): void
    {
        $resetAt = now()->addSeconds(45);

        $this->integration->at('/api/charge')->post(function (RequestContext $ctx) use ($resetAt): array {
            $ctx->reportResponseMetadata(rateLimitRemaining: 0, rateLimitResetAt: $resetAt);

            return ['ok' => true];
        });

        $this->expectException(RateLimitExceededException::class);

        $this->integration->at('/api/charge')->post(fn (): array => ['ok' => true]);
    }

    public function test_remaining_above_zero_does_not_suppress(): void
    {
        $this->integration->at('/api/charge')->post(function (RequestContext $ctx): array {
            $ctx->reportResponseMetadata(
                rateLimitRemaining: 10,
                rateLimitResetAt: now()->addMinute(),
            );

            return ['ok' => true];
        });

        $result = $this->integration->at('/api/charge')->post(fn (): array => ['ok' => true]);
        $this->assertSame(['ok' => true], $result);
    }

    public function test_remaining_zero_without_reset_at_does_not_suppress(): void
    {
        // Adapter reports remaining=0 but no reset window — we don't have
        // enough info to know how long to suppress for, so do nothing.
        $this->integration->at('/api/charge')->post(function (RequestContext $ctx): array {
            $ctx->reportResponseMetadata(rateLimitRemaining: 0);

            return ['ok' => true];
        });

        $result = $this->integration->at('/api/charge')->post(fn (): array => ['ok' => true]);
        $this->assertSame(['ok' => true], $result);
    }

    public function test_suppression_lifts_after_window_expires(): void
    {
        // Suppress for 1 second, then manually expire the cache key to
        // simulate the window passing.
        $suppressKey = 'integrations:rate:suppress:'.$this->integration->id;
        Cache::put($suppressKey, now()->subSecond()->timestamp, 60);

        // Even with wait=0, an already-expired suppression should clear
        // and let the request through.
        $result = $this->integration->at('/api/charge')->post(fn (): array => ['ok' => true]);
        $this->assertSame(['ok' => true], $result);
    }

    public function test_no_metadata_falls_back_to_existing_bucket_behavior(): void
    {
        // No reportResponseMetadata call — should pass through fine and use
        // only the existing bucket-based rate limiter.
        $result = $this->integration->at('/api/charge')->post(fn (): array => ['ok' => true]);
        $this->assertSame(['ok' => true], $result);
    }
}
