<?php

declare(strict_types=1);

namespace Integrations\Tests\Unit\Commands;

use Integrations\Enums\RateLimitWindow;
use Integrations\IntegrationManager;
use Integrations\Models\Integration;
use Integrations\RateLimit;
use Integrations\Tests\Fixtures\TestProvider;
use Integrations\Tests\TestCase;

class RateLimitCommandTest extends TestCase
{
    private Integration $integration;

    protected function setUp(): void
    {
        parent::setUp();

        app(IntegrationManager::class)->register('test', TestProvider::class);
        $this->integration = Integration::create(['provider' => 'test', 'name' => 'Test']);
        $this->integration->refresh();
    }

    public function test_set_stores_override(): void
    {
        $this->artisan('integrations:rate-limit', [
            'integration' => (string) $this->integration->id,
            'action' => 'set',
            '--limit' => '50',
            '--window' => '60',
        ])->assertSuccessful();

        $this->integration->refresh();
        $limit = $this->integration->effectiveRateLimit();
        $this->assertNotNull($limit);
        $this->assertSame(50, $limit->limit);
        $this->assertSame(RateLimitWindow::Fixed, $limit->window);
    }

    public function test_sliding_flag_sets_sliding_window(): void
    {
        $this->artisan('integrations:rate-limit', [
            'integration' => (string) $this->integration->id,
            'action' => 'set',
            '--limit' => '50',
            '--window' => '60',
            '--sliding' => true,
        ])->assertSuccessful();

        $this->integration->refresh();
        $limit = $this->integration->effectiveRateLimit();
        $this->assertNotNull($limit);
        $this->assertSame(RateLimitWindow::Sliding, $limit->window);
    }

    public function test_set_without_limit_fails(): void
    {
        $this->artisan('integrations:rate-limit', [
            'integration' => (string) $this->integration->id,
            'action' => 'set',
            '--window' => '60',
        ])->assertFailed();
    }

    public function test_zero_limit_fails(): void
    {
        $this->artisan('integrations:rate-limit', [
            'integration' => (string) $this->integration->id,
            'action' => 'set',
            '--limit' => '0',
            '--window' => '60',
        ])->assertFailed();
    }

    public function test_clear_removes_override(): void
    {
        $this->integration->overrideRateLimit(RateLimit::per(2, 60));

        $this->artisan('integrations:rate-limit', [
            'integration' => (string) $this->integration->id,
            'action' => 'clear',
        ])->assertSuccessful();

        $this->integration->refresh();
        $this->assertNull($this->integration->rate_limit_override);
    }

    public function test_status_shows_source(): void
    {
        $this->integration->overrideRateLimit(RateLimit::per(2, 60));

        $this->artisan('integrations:rate-limit', [
            'integration' => (string) $this->integration->id,
            'action' => 'status',
        ])->assertSuccessful()->expectsOutputToContain('override');
    }
}
