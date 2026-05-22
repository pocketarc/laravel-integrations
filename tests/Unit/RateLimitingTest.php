<?php

declare(strict_types=1);

namespace Integrations\Tests\Unit;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Integrations\Exceptions\RateLimitExceededException;
use Integrations\Exceptions\RetryableException;
use Integrations\IntegrationManager;
use Integrations\Models\Integration;
use Integrations\RateLimit;
use Integrations\RateLimiter;
use Integrations\Tests\Fixtures\TestProvider;
use Integrations\Tests\TestCase;

class RateLimitingTest extends TestCase
{
    /** A window-start timestamp that is an exact multiple of 60. */
    private const WINDOW = 1_700_000_040;

    private Integration $integration;

    protected function setUp(): void
    {
        parent::setUp();

        app(IntegrationManager::class)->register('test', TestProvider::class);

        $this->integration = Integration::create(['provider' => 'test', 'name' => 'Test']);
        $this->integration->refresh();

        // Throw immediately instead of sleeping, except where a test opts
        // back in to exercise the wait loop.
        config(['integrations.rate_limiting.max_wait_seconds' => 0]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_allows_a_full_budget_burst_within_a_fixed_window(): void
    {
        Carbon::setTestNow(Carbon::createFromTimestamp(self::WINDOW));
        $limiter = $this->limiterWith(RateLimit::per(5, 60));

        for ($i = 0; $i < 5; $i++) {
            $limiter->enforce();
        }

        $this->assertSame(5, $this->bucket(self::WINDOW));
    }

    public function test_throws_when_the_fixed_window_is_exhausted(): void
    {
        Carbon::setTestNow(Carbon::createFromTimestamp(self::WINDOW));
        $limiter = $this->limiterWith(RateLimit::per(5, 60));
        Cache::put($this->bucketKey(self::WINDOW), 5, 120);

        try {
            $limiter->enforce();
            $this->fail('Expected RateLimitExceededException.');
        } catch (RateLimitExceededException $e) {
            $this->assertSame(60, $e->retryAfterSeconds);
            $this->assertSame($this->integration->id, $e->integration->id);

            $rateLimit = $e->rateLimit;
            if ($rateLimit === null) {
                $this->fail('Expected the exception to carry its RateLimit.');
            }
            $this->assertSame(5, $rateLimit->limit);
        }
    }

    public function test_a_fixed_window_resets_at_the_next_boundary(): void
    {
        $limiter = $this->limiterWith(RateLimit::per(3, 60));

        Carbon::setTestNow(Carbon::createFromTimestamp(self::WINDOW));
        Cache::put($this->bucketKey(self::WINDOW), 3, 120);

        try {
            $limiter->enforce();
            $this->fail('Expected the window to be exhausted.');
        } catch (RateLimitExceededException) {
            // expected
        }

        // The next window starts with fresh capacity.
        Carbon::setTestNow(Carbon::createFromTimestamp(self::WINDOW + 60));
        $limiter->enforce();

        $this->assertSame(1, $this->bucket(self::WINDOW + 60));
    }

    public function test_sliding_window_counts_the_previous_window(): void
    {
        // Frozen exactly on the boundary, so the previous window weighs in
        // full. The current window is empty: a fixed window would allow this
        // request, a sliding one must not.
        Carbon::setTestNow(Carbon::createFromTimestamp(self::WINDOW));
        $limiter = $this->limiterWith(RateLimit::per(5, 60)->sliding());

        Cache::put($this->bucketKey(self::WINDOW - 60), 5, 120);

        $this->expectException(RateLimitExceededException::class);

        $limiter->enforce();
    }

    public function test_sliding_window_decays_the_previous_window_over_time(): void
    {
        // Halfway through the window the previous window's contribution is
        // halved, so a count that blocked at the boundary now leaves room:
        // 0 (current) + 6 * (1 - 0.5) = 3, under the limit of 5.
        Carbon::setTestNow(Carbon::createFromTimestamp(self::WINDOW + 30));
        $limiter = $this->limiterWith(RateLimit::per(5, 60)->sliding());

        Cache::put($this->bucketKey(self::WINDOW - 60), 6, 120);

        $limiter->enforce();

        $this->assertSame(1, $this->bucket(self::WINDOW));
    }

    public function test_sliding_window_retry_after_reflects_decay_not_the_window_boundary(): void
    {
        // Halfway (f = 0.5) through a 60s window: current bucket 2, previous
        // bucket 8, so the sliding estimate is 2 + 8 * (1 - 0.5) = 6, over
        // the limit of 5. It decays to 4 (one below the limit, where ceil()
        // clears it) at f = 0.75, which is 15s away, well before the 30s
        // left until the window boundary.
        Carbon::setTestNow(Carbon::createFromTimestamp(self::WINDOW + 30));
        $limiter = $this->limiterWith(RateLimit::per(5, 60)->sliding());

        Cache::put($this->bucketKey(self::WINDOW), 2, 120);
        Cache::put($this->bucketKey(self::WINDOW - 60), 8, 120);

        try {
            $limiter->enforce();
            $this->fail('Expected RateLimitExceededException.');
        } catch (RateLimitExceededException $e) {
            $this->assertSame(15, $e->retryAfterSeconds);
        }
    }

    public function test_a_null_limit_never_throttles(): void
    {
        $limiter = $this->limiterWith(null);

        // Far more calls than any window would permit; none are throttled.
        for ($i = 0; $i < 250; $i++) {
            $limiter->enforce();
        }

        // An unlimited provider writes no bucket at all.
        $minuteWindow = intdiv(now()->getTimestamp(), 60) * 60;
        $this->assertNull(Cache::get($this->bucketKey($minuteWindow)));
    }

    public function test_a_stale_pre_4_0_cache_key_is_ignored(): void
    {
        Carbon::setTestNow(Carbon::createFromTimestamp(self::WINDOW));
        $limiter = $this->limiterWith(RateLimit::per(5, 60));

        // Pre-4.0 buckets were keyed ':rate:{id}:{Y-m-d-H-i}', which never
        // collides with the new integer windowStart keys; a leftover one is
        // inert, not a phantom 999 requests.
        Cache::put('integrations:rate:'.$this->integration->id.':'.now()->format('Y-m-d-H-i'), 999, 120);

        $limiter->enforce();

        $this->assertSame(1, $this->bucket(self::WINDOW));
    }

    public function test_sleeps_within_max_wait_then_records_in_the_next_window(): void
    {
        // A real clock (not frozen): the limiter sleeps to the window edge,
        // then records in the next, fresh window. A short window keeps it fast.
        config(['integrations.rate_limiting.max_wait_seconds' => 5]);
        $limiter = $this->limiterWith(RateLimit::per(1, 2));

        $windowStart = intdiv(now()->getTimestamp(), 2) * 2;
        Cache::put($this->bucketKey($windowStart), 1, 120);

        $limiter->enforce();

        // The request waited out the exhausted window and landed in the next.
        $this->assertSame(1, $this->bucket($windowStart + 2));
    }

    public function test_rate_limit_exception_is_not_retryable(): void
    {
        // It must not be a RetryableException: RequestExecutor would then
        // retry it in-process and sleep a worker for the whole window.
        $e = new RateLimitExceededException($this->integration, 120, RateLimit::perHour(5000));

        $this->assertNotInstanceOf(RetryableException::class, $e);
        $this->assertSame(120, $e->retryAfterSeconds);
        $this->assertStringContainsString('Capacity expected in 120s', $e->getMessage());
        $this->assertStringContainsString('5000 per 3600s', $e->getMessage());
    }

    /**
     * Bind a TestProvider reporting the given limit and return a RateLimiter
     * for the integration. A null limit means the provider is unlimited.
     */
    private function limiterWith(?RateLimit $limit): RateLimiter
    {
        $provider = new TestProvider;
        $provider->rateLimit = $limit;
        $this->app->instance(TestProvider::class, $provider);

        return new RateLimiter($this->integration);
    }

    private function bucketKey(int $windowStart): string
    {
        return 'integrations:rate:'.$this->integration->id.':'.$windowStart;
    }

    private function bucket(int $windowStart): int
    {
        $value = Cache::get($this->bucketKey($windowStart));

        return is_numeric($value) ? (int) $value : 0;
    }
}
