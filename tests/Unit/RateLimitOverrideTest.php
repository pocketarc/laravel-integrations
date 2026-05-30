<?php

declare(strict_types=1);

namespace Integrations\Tests\Unit;

use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Integrations\Enums\RateLimitWindow;
use Integrations\IntegrationManager;
use Integrations\Models\Integration;
use Integrations\RateLimit;
use Integrations\Tests\Fixtures\PlainProvider;
use Integrations\Tests\Fixtures\TestProvider;
use Integrations\Tests\TestCase;

class RateLimitOverrideTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        Cache::flush();
        parent::tearDown();
    }

    private function integration(string $provider = 'test', string $class = TestProvider::class): Integration
    {
        app(IntegrationManager::class)->register($provider, $class);
        $integration = Integration::create(['provider' => $provider, 'name' => 'Test']);

        return $integration->refresh();
    }

    public function test_override_takes_precedence_over_provider(): void
    {
        $integration = $this->integration();
        $integration->overrideRateLimit(RateLimit::per(2, 60));

        $limit = $integration->effectiveRateLimit();

        $this->assertNotNull($limit);
        $this->assertSame(2, $limit->limit);
        $this->assertSame(60, $limit->windowSeconds);
    }

    public function test_expired_override_falls_back_to_provider(): void
    {
        $integration = $this->integration();
        $integration->overrideRateLimit(RateLimit::per(2, 60), now()->addMinutes(5));

        Carbon::setTestNow(Carbon::now()->addMinutes(6));

        $limit = $integration->effectiveRateLimit();

        // TestProvider's default is 100/min.
        $this->assertNotNull($limit);
        $this->assertSame(100, $limit->limit);

        $integration->refresh();
        $this->assertNull($integration->rate_limit_override);
    }

    public function test_clear_override_falls_back_to_provider(): void
    {
        $integration = $this->integration();
        $integration->overrideRateLimit(RateLimit::per(2, 60));
        $integration->clearRateLimitOverride();

        $limit = $integration->effectiveRateLimit();
        $this->assertNotNull($limit);
        $this->assertSame(100, $limit->limit);
    }

    public function test_override_applies_to_provider_without_scheduled_sync(): void
    {
        // PlainProvider is a bare IntegrationProvider (no HasScheduledSync), so
        // it has no default limit; the override is the only source.
        $integration = $this->integration('plain', PlainProvider::class);
        $this->assertNull($integration->effectiveRateLimit());

        $integration->overrideRateLimit(RateLimit::per(5, 60));
        $limit = $integration->effectiveRateLimit();

        $this->assertNotNull($limit);
        $this->assertSame(5, $limit->limit);
    }

    public function test_overrides_disabled_in_config_uses_provider(): void
    {
        config(['integrations.rate_limiting.overrides_enabled' => false]);
        $integration = $this->integration();
        $integration->overrideRateLimit(RateLimit::per(2, 60));

        $limit = $integration->effectiveRateLimit();
        $this->assertNotNull($limit);
        $this->assertSame(100, $limit->limit);
    }

    public function test_sliding_override_round_trips(): void
    {
        $integration = $this->integration();
        $integration->overrideRateLimit(RateLimit::per(10, 60)->sliding());

        $limit = $integration->effectiveRateLimit();
        $this->assertNotNull($limit);
        $this->assertSame(RateLimitWindow::Sliding, $limit->window);
    }

    public function test_malformed_override_falls_back_to_provider(): void
    {
        $integration = $this->integration();
        // Write a malformed override directly (missing windowSeconds/window).
        $integration->forceFill(['rate_limit_override' => ['limit' => 5]])->save();
        $integration->refresh();

        $limit = $integration->effectiveRateLimit();
        $this->assertNotNull($limit);
        $this->assertSame(100, $limit->limit);
    }
}
