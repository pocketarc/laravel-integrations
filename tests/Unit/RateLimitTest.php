<?php

declare(strict_types=1);

namespace Integrations\Tests\Unit;

use Integrations\Enums\RateLimitWindow;
use Integrations\RateLimit;
use Integrations\Tests\TestCase;
use InvalidArgumentException;

class RateLimitTest extends TestCase
{
    public function test_per_minute_builds_a_60_second_fixed_window(): void
    {
        $limit = RateLimit::perMinute(700);

        $this->assertSame(700, $limit->limit);
        $this->assertSame(60, $limit->windowSeconds);
        $this->assertSame(RateLimitWindow::Fixed, $limit->window);
    }

    public function test_per_hour_builds_a_3600_second_fixed_window(): void
    {
        $limit = RateLimit::perHour(5000);

        $this->assertSame(5000, $limit->limit);
        $this->assertSame(3600, $limit->windowSeconds);
        $this->assertSame(RateLimitWindow::Fixed, $limit->window);
    }

    public function test_per_day_builds_an_86400_second_fixed_window(): void
    {
        $limit = RateLimit::perDay(10_000);

        $this->assertSame(10_000, $limit->limit);
        $this->assertSame(86_400, $limit->windowSeconds);
        $this->assertSame(RateLimitWindow::Fixed, $limit->window);
    }

    public function test_per_builds_an_arbitrary_fixed_window(): void
    {
        $limit = RateLimit::per(30, 10);

        $this->assertSame(30, $limit->limit);
        $this->assertSame(10, $limit->windowSeconds);
        $this->assertSame(RateLimitWindow::Fixed, $limit->window);
    }

    public function test_sliding_returns_a_sliding_copy(): void
    {
        $fixed = RateLimit::perMinute(700);
        $sliding = $fixed->sliding();

        $this->assertSame(RateLimitWindow::Fixed, $fixed->window);
        $this->assertSame(RateLimitWindow::Sliding, $sliding->window);
        $this->assertSame(700, $sliding->limit);
        $this->assertSame(60, $sliding->windowSeconds);
    }

    public function test_fixed_returns_a_fixed_copy(): void
    {
        $sliding = RateLimit::perMinute(700)->sliding();
        $fixed = $sliding->fixed();

        $this->assertSame(RateLimitWindow::Sliding, $sliding->window);
        $this->assertSame(RateLimitWindow::Fixed, $fixed->window);
    }

    public function test_rejects_a_limit_below_one(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new RateLimit(0, 60);
    }

    public function test_rejects_a_window_below_one(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new RateLimit(100, 0);
    }
}
