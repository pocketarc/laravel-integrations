<?php

declare(strict_types=1);

namespace Integrations\Tests\Unit;

use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Integrations\CircuitBreaker;
use Integrations\Enums\FailureClass;
use Integrations\Events\CircuitClosed;
use Integrations\Events\CircuitOpened;
use Integrations\IntegrationManager;
use Integrations\Models\Integration;
use Integrations\Tests\Fixtures\TestProvider;
use Integrations\Tests\TestCase;

class CircuitEventsTest extends TestCase
{
    private Integration $integration;

    protected function setUp(): void
    {
        parent::setUp();

        app(IntegrationManager::class)->register('test', TestProvider::class);
        $this->integration = Integration::create(['provider' => 'test', 'name' => 'Test']);
        $this->integration->refresh();

        config(['integrations.circuit_breaker.strategy' => 'count']);
        config(['integrations.circuit_breaker.threshold' => 2]);
        config(['integrations.circuit_breaker.cooldown_seconds' => 10]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        Cache::flush();
        parent::tearDown();
    }

    public function test_trip_fires_circuit_opened_once(): void
    {
        Event::fake([CircuitOpened::class]);
        $breaker = new CircuitBreaker($this->integration);

        $breaker->recordFailure(FailureClass::Upstream);
        $breaker->recordFailure(FailureClass::Upstream); // trips

        // Further failures while already open must not re-fire.
        $breaker->recordFailure(FailureClass::Upstream);

        Event::assertDispatchedTimes(CircuitOpened::class, 1);
        Event::assertDispatched(CircuitOpened::class, function (CircuitOpened $e): bool {
            return $e->reason === 'threshold_reached';
        });
    }

    public function test_half_open_failure_fires_circuit_opened(): void
    {
        Event::fake([CircuitOpened::class]);
        $breaker = new CircuitBreaker($this->integration);

        $breaker->recordFailure(FailureClass::Upstream);
        $breaker->recordFailure(FailureClass::Upstream); // trips

        Carbon::setTestNow(Carbon::now()->addSeconds(15));
        $breaker->enforce(); // half-open probe
        $breaker->recordFailure(FailureClass::Upstream); // probe fails

        Event::assertDispatched(CircuitOpened::class, function (CircuitOpened $e): bool {
            return $e->reason === 'half_open_probe_failed';
        });
    }

    public function test_probe_success_fires_circuit_closed(): void
    {
        Event::fake([CircuitClosed::class]);
        $breaker = new CircuitBreaker($this->integration);

        $breaker->recordFailure(FailureClass::Upstream);
        $breaker->recordFailure(FailureClass::Upstream); // trips

        Carbon::setTestNow(Carbon::now()->addSeconds(15));
        $breaker->enforce(); // half-open probe
        $breaker->recordSuccess(); // closes

        Event::assertDispatched(CircuitClosed::class, function (CircuitClosed $e): bool {
            return $e->reason === 'half_open_probe_succeeded';
        });
    }

    public function test_steady_state_success_fires_nothing(): void
    {
        Event::fake([CircuitOpened::class, CircuitClosed::class]);
        $breaker = new CircuitBreaker($this->integration);

        $breaker->recordSuccess();
        $breaker->recordSuccess();

        Event::assertNotDispatched(CircuitOpened::class);
        Event::assertNotDispatched(CircuitClosed::class);
    }

    public function test_force_open_and_close_fire_events(): void
    {
        Event::fake([CircuitOpened::class, CircuitClosed::class]);

        $this->integration->forceCircuitOpen();
        $this->integration->forceCircuitClosed();

        Event::assertDispatched(CircuitOpened::class, fn (CircuitOpened $e): bool => $e->reason === 'forced_open');
        Event::assertDispatched(CircuitClosed::class, fn (CircuitClosed $e): bool => $e->reason === 'forced_closed');
    }
}
