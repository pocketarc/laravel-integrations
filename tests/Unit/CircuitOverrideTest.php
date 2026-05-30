<?php

declare(strict_types=1);

namespace Integrations\Tests\Unit;

use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Integrations\CircuitBreaker;
use Integrations\Enums\CircuitOverride;
use Integrations\Enums\FailureClass;
use Integrations\Exceptions\CircuitOpenException;
use Integrations\IntegrationManager;
use Integrations\Models\Integration;
use Integrations\Tests\Fixtures\TestProvider;
use Integrations\Tests\TestCase;

class CircuitOverrideTest extends TestCase
{
    private Integration $integration;

    protected function setUp(): void
    {
        parent::setUp();

        app(IntegrationManager::class)->register('test', TestProvider::class);
        $this->integration = Integration::create(['provider' => 'test', 'name' => 'Test']);
        $this->integration->refresh();

        config(['integrations.circuit_breaker.strategy' => 'count']);
        config(['integrations.circuit_breaker.threshold' => 3]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        Cache::flush();
        parent::tearDown();
    }

    public function test_forced_open_throws_with_no_failures(): void
    {
        $this->integration->forceCircuitOpen();

        $this->expectException(CircuitOpenException::class);
        (new CircuitBreaker($this->integration))->enforce();
    }

    public function test_forced_open_is_not_closed_by_success(): void
    {
        $this->integration->forceCircuitOpen();
        $breaker = new CircuitBreaker($this->integration);

        $breaker->recordSuccess();

        $this->expectException(CircuitOpenException::class);
        $breaker->enforce();
    }

    public function test_forced_closed_never_trips(): void
    {
        $this->integration->forceCircuitClosed();
        $breaker = new CircuitBreaker($this->integration);

        for ($i = 0; $i < 10; $i++) {
            $breaker->recordFailure(FailureClass::Upstream);
        }

        $breaker->enforce();
        $this->assertTrue(true);
    }

    public function test_disabled_bypasses_breaker(): void
    {
        $this->integration->disableCircuit();
        $breaker = new CircuitBreaker($this->integration);

        for ($i = 0; $i < 10; $i++) {
            $breaker->recordFailure(FailureClass::Upstream);
        }

        $breaker->enforce();
        $this->assertTrue(true);
    }

    public function test_expired_override_reverts_to_auto_and_is_cleared(): void
    {
        $this->integration->forceCircuitOpen(now()->addMinutes(5));
        $this->assertSame(CircuitOverride::ForcedOpen, $this->integration->effectiveCircuitOverride());

        Carbon::setTestNow(Carbon::now()->addMinutes(6));

        $this->assertNull($this->integration->effectiveCircuitOverride());

        // The expired row is cleared best-effort on read.
        $this->integration->refresh();
        $this->assertNull($this->integration->circuit_override);
        $this->assertNull($this->integration->circuit_override_until);
    }

    public function test_clear_override_resets_breaker_state(): void
    {
        // Open the breaker through the normal path, then force it open and
        // clear: clearing must reset the cached OPEN state so auto starts clean.
        $breaker = new CircuitBreaker($this->integration);
        for ($i = 0; $i < 3; $i++) {
            $breaker->recordFailure(FailureClass::Upstream);
        }

        $this->integration->clearCircuitOverride();

        // No override, and the cached failure state was reset, so enforce passes.
        (new CircuitBreaker($this->integration))->enforce();
        $this->assertTrue(true);
    }

    public function test_overrides_disabled_in_config_ignores_override(): void
    {
        config(['integrations.circuit_breaker.overrides_enabled' => false]);
        $this->integration->forceCircuitOpen();

        // The column is set, but the global toggle makes it inert.
        $this->assertNull($this->integration->effectiveCircuitOverride());
        (new CircuitBreaker($this->integration))->enforce();
        $this->assertTrue(true);
    }
}
