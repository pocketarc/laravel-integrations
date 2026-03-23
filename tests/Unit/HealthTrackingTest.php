<?php

declare(strict_types=1);

namespace Integrations\Tests\Unit;

use Illuminate\Support\Facades\Event;
use Integrations\Enums\HealthStatus;
use Integrations\Events\IntegrationHealthChanged;
use Integrations\Models\Integration;
use Integrations\Tests\TestCase;

class HealthTrackingTest extends TestCase
{
    public function test_record_success_resets_failures(): void
    {
        $integration = Integration::create([
            'provider' => 'test',
            'name' => 'Test',
            'consecutive_failures' => 5,
            'health_status' => HealthStatus::Degraded,
        ]);

        $integration->recordSuccess();

        $integration->refresh();
        $this->assertSame(0, $integration->consecutive_failures);
        $this->assertSame(HealthStatus::Healthy, $integration->health_status);
    }

    public function test_record_failure_increments_counter(): void
    {
        $integration = Integration::create([
            'provider' => 'test',
            'name' => 'Test',
            'health_status' => HealthStatus::Healthy,
        ]);

        $integration->refresh();
        $integration->recordFailure();

        $integration->refresh();
        $this->assertSame(1, $integration->consecutive_failures);
        $this->assertNotNull($integration->last_error_at);
    }

    public function test_transitions_to_degraded(): void
    {
        Event::fake();

        $integration = Integration::create([
            'provider' => 'test',
            'name' => 'Test',
            'consecutive_failures' => 4,
            'health_status' => HealthStatus::Healthy,
        ]);

        $integration->refresh();
        $integration->recordFailure();

        $integration->refresh();
        $this->assertSame(HealthStatus::Degraded, $integration->health_status);

        Event::assertDispatched(IntegrationHealthChanged::class, function ($event) {
            return $event->previousStatus === HealthStatus::Healthy && $event->newStatus === HealthStatus::Degraded;
        });
    }

    public function test_transitions_to_failing(): void
    {
        Event::fake();

        $integration = Integration::create([
            'provider' => 'test',
            'name' => 'Test',
            'consecutive_failures' => 19,
            'health_status' => HealthStatus::Degraded,
        ]);

        $integration->refresh();
        $integration->recordFailure();

        $integration->refresh();
        $this->assertSame(HealthStatus::Failing, $integration->health_status);

        Event::assertDispatched(IntegrationHealthChanged::class);
    }

    public function test_recovery_fires_event(): void
    {
        Event::fake();

        $integration = Integration::create([
            'provider' => 'test',
            'name' => 'Test',
            'consecutive_failures' => 10,
            'health_status' => HealthStatus::Degraded,
        ]);

        $integration->refresh();
        $integration->recordSuccess();

        Event::assertDispatched(IntegrationHealthChanged::class, function ($event) {
            return $event->previousStatus === HealthStatus::Degraded && $event->newStatus === HealthStatus::Healthy;
        });
    }

    public function test_no_event_when_status_unchanged(): void
    {
        Event::fake();

        $integration = Integration::create([
            'provider' => 'test',
            'name' => 'Test',
            'health_status' => HealthStatus::Healthy,
        ]);

        $integration->refresh();
        $integration->recordSuccess();

        Event::assertNotDispatched(IntegrationHealthChanged::class);
    }
}
