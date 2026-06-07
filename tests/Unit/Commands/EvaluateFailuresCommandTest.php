<?php

declare(strict_types=1);

namespace Integrations\Tests\Unit\Commands;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Integrations\Enums\FailureClass;
use Integrations\Events\ElevatedFailureRate;
use Integrations\Events\FailureRateRecovered;
use Integrations\IntegrationManager;
use Integrations\Models\Integration;
use Integrations\Tests\Fixtures\TestProvider;
use Integrations\Tests\TestCase;

class EvaluateFailuresCommandTest extends TestCase
{
    private Integration $integration;

    protected function setUp(): void
    {
        parent::setUp();

        app(IntegrationManager::class)->register('test', TestProvider::class);
        $this->integration = Integration::create(['provider' => 'test', 'name' => 'Test']);
        $this->integration->refresh();

        config([
            'integrations.observability.anomaly_enabled' => true,
            'integrations.observability.anomaly_window_minutes' => 15,
            'integrations.observability.anomaly_failure_rate_threshold' => 25,
            'integrations.observability.anomaly_minimum_requests' => 4,
        ]);
    }

    public function test_fires_when_the_failure_rate_crosses_the_threshold(): void
    {
        Event::fake([ElevatedFailureRate::class, FailureRateRecovered::class]);
        $this->seedRequests(failed: 3, successful: 1);

        $this->artisan('integrations:evaluate-failures')->assertSuccessful();

        Event::assertDispatched(ElevatedFailureRate::class, function (ElevatedFailureRate $event): bool {
            return $event->integration->is($this->integration)
                && $event->dominantClass === FailureClass::Upstream
                && $event->failureRate >= 25.0
                && $event->observedRequests === 4;
        });
    }

    public function test_fires_once_per_incident_while_still_elevated(): void
    {
        Event::fake([ElevatedFailureRate::class]);
        $this->seedRequests(failed: 3, successful: 1);

        $this->artisan('integrations:evaluate-failures')->assertSuccessful();
        $this->artisan('integrations:evaluate-failures')->assertSuccessful();

        Event::assertDispatchedTimes(ElevatedFailureRate::class, 1);
    }

    public function test_fires_recovery_and_re_arms_when_the_rate_drops(): void
    {
        Event::fake([ElevatedFailureRate::class, FailureRateRecovered::class]);
        $this->seedRequests(failed: 3, successful: 1);

        $this->artisan('integrations:evaluate-failures')->assertSuccessful();

        // Dilute the window so the rate falls below the threshold.
        $this->seedRequests(failed: 0, successful: 100);
        $this->artisan('integrations:evaluate-failures')->assertSuccessful();

        Event::assertDispatched(FailureRateRecovered::class, function (FailureRateRecovered $event): bool {
            return $event->integration->is($this->integration);
        });

        // The open marker is cleared on recovery, so a fresh spike alerts again.
        $this->seedRequests(failed: 200, successful: 0);
        $this->artisan('integrations:evaluate-failures')->assertSuccessful();

        Event::assertDispatchedTimes(ElevatedFailureRate::class, 2);
    }

    public function test_recovery_survives_a_cache_flush(): void
    {
        Event::fake([ElevatedFailureRate::class, FailureRateRecovered::class]);
        $this->seedRequests(failed: 3, successful: 1);
        $this->artisan('integrations:evaluate-failures')->assertSuccessful();

        // The open state is durable (a DB column), so wiping the cache mid-incident
        // must not drop the pending recovery.
        Cache::flush();

        $this->seedRequests(failed: 0, successful: 100);
        $this->artisan('integrations:evaluate-failures')->assertSuccessful();

        Event::assertDispatched(FailureRateRecovered::class);
    }

    public function test_does_not_fire_below_the_minimum_requests_floor(): void
    {
        Event::fake([ElevatedFailureRate::class]);
        // 3 failures, 100% rate, but below the floor of 4.
        $this->seedRequests(failed: 3, successful: 0);

        $this->artisan('integrations:evaluate-failures')->assertSuccessful();

        Event::assertNotDispatched(ElevatedFailureRate::class);
    }

    public function test_does_not_fire_below_the_threshold(): void
    {
        Event::fake([ElevatedFailureRate::class, FailureRateRecovered::class]);
        // 1 failure in 10 requests = 10%, below 25%.
        $this->seedRequests(failed: 1, successful: 9);

        $this->artisan('integrations:evaluate-failures')->assertSuccessful();

        Event::assertNotDispatched(ElevatedFailureRate::class);
        Event::assertNotDispatched(FailureRateRecovered::class);
    }

    public function test_no_events_when_disabled(): void
    {
        config(['integrations.observability.anomaly_enabled' => false]);
        Event::fake([ElevatedFailureRate::class]);
        $this->seedRequests(failed: 10, successful: 0);

        $this->artisan('integrations:evaluate-failures')->assertSuccessful();

        Event::assertNotDispatched(ElevatedFailureRate::class);
    }

    private function seedRequests(int $failed, int $successful): void
    {
        for ($i = 0; $i < $failed; $i++) {
            $this->integration->requests()->create([
                'endpoint' => '/x',
                'method' => 'GET',
                'response_success' => false,
                'response_code' => 500,
                'failure_class' => FailureClass::Upstream,
            ]);
        }

        for ($i = 0; $i < $successful; $i++) {
            $this->integration->requests()->create([
                'endpoint' => '/x',
                'method' => 'GET',
                'response_success' => true,
                'response_code' => 200,
            ]);
        }
    }
}
