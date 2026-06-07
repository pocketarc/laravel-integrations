<?php

declare(strict_types=1);

namespace Integrations\Tests\Unit;

use Integrations\Enums\HealthStatus;
use Integrations\Events\CircuitClosed;
use Integrations\Events\CircuitOpened;
use Integrations\Events\IntegrationDisabled;
use Integrations\Events\IntegrationHealthChanged;
use Integrations\IntegrationManager;
use Integrations\Models\Integration;
use Integrations\Models\IntegrationIncident;
use Integrations\Tests\Fixtures\TestProvider;
use Integrations\Tests\TestCase;

class IncidentTrackingTest extends TestCase
{
    private Integration $integration;

    protected function setUp(): void
    {
        parent::setUp();

        app(IntegrationManager::class)->register('test', TestProvider::class);
        $this->integration = Integration::create(['provider' => 'test', 'name' => 'Test']);
        $this->integration->refresh();
    }

    public function test_health_degradation_opens_one_incident(): void
    {
        IntegrationHealthChanged::dispatch($this->integration, HealthStatus::Healthy, HealthStatus::Degraded);

        $this->assertDatabaseCount('integration_incidents', 1);

        $incident = IntegrationIncident::query()->firstOrFail();
        $this->assertSame(IntegrationIncident::STATUS_OPEN, $incident->status);
        $this->assertSame(IntegrationIncident::SOURCE_HEALTH, $incident->source);
        $this->assertSame('health_degraded', $incident->reason);
        $this->assertSame(HealthStatus::Degraded, $incident->peak_severity);
        $this->assertNotNull($incident->opened_at);
        $this->assertNull($incident->closed_at);
    }

    public function test_escalation_keeps_one_incident_and_raises_peak(): void
    {
        IntegrationHealthChanged::dispatch($this->integration, HealthStatus::Healthy, HealthStatus::Degraded);
        IntegrationHealthChanged::dispatch($this->integration, HealthStatus::Degraded, HealthStatus::Failing);

        $this->assertDatabaseCount('integration_incidents', 1);
        $this->assertSame(HealthStatus::Failing, IntegrationIncident::query()->firstOrFail()->peak_severity);
    }

    public function test_recovery_closes_the_incident_and_retains_peak(): void
    {
        IntegrationHealthChanged::dispatch($this->integration, HealthStatus::Healthy, HealthStatus::Degraded);
        IntegrationHealthChanged::dispatch($this->integration, HealthStatus::Degraded, HealthStatus::Failing);
        IntegrationHealthChanged::dispatch($this->integration, HealthStatus::Failing, HealthStatus::Healthy);

        $incident = IntegrationIncident::query()->firstOrFail();
        $this->assertSame(IntegrationIncident::STATUS_CLOSED, $incident->status);
        $this->assertNotNull($incident->closed_at);
        $this->assertSame(HealthStatus::Failing, $incident->peak_severity);
        $this->assertFalse($this->reloaded()->has_open_incident);
    }

    public function test_circuit_trip_opens_an_incident_at_failing(): void
    {
        CircuitOpened::dispatch($this->integration, 'threshold_reached');

        $incident = IntegrationIncident::query()->firstOrFail();
        $this->assertSame(IntegrationIncident::SOURCE_CIRCUIT, $incident->source);
        $this->assertSame('threshold_reached', $incident->reason);
        $this->assertSame(HealthStatus::Failing, $incident->peak_severity);
    }

    public function test_circuit_trip_folds_into_an_open_health_incident(): void
    {
        IntegrationHealthChanged::dispatch($this->integration, HealthStatus::Healthy, HealthStatus::Degraded);
        CircuitOpened::dispatch($this->integration, 'threshold_reached');

        $this->assertDatabaseCount('integration_incidents', 1);
        // Peak raised from Degraded to Failing by the circuit trip.
        $this->assertSame(HealthStatus::Failing, IntegrationIncident::query()->firstOrFail()->peak_severity);
    }

    public function test_forced_circuit_events_do_not_open_or_close(): void
    {
        CircuitOpened::dispatch($this->integration, 'forced_open');
        $this->assertDatabaseCount('integration_incidents', 0);

        // Open a real incident, then a forced close must leave it open.
        IntegrationHealthChanged::dispatch($this->integration, HealthStatus::Healthy, HealthStatus::Degraded);
        CircuitClosed::dispatch($this->integration, 'forced_closed');

        $this->assertTrue($this->reloaded()->has_open_incident);
    }

    public function test_auto_disable_records_one_incident_at_disabled(): void
    {
        // recordFailure fires IntegrationDisabled then IntegrationHealthChanged(→Disabled);
        // both must collapse into a single row at peak Disabled.
        IntegrationDisabled::dispatch($this->integration);
        IntegrationHealthChanged::dispatch($this->integration, HealthStatus::Failing, HealthStatus::Disabled);

        $this->assertDatabaseCount('integration_incidents', 1);
        $this->assertSame(HealthStatus::Disabled, IntegrationIncident::query()->firstOrFail()->peak_severity);
    }

    public function test_circuit_close_keeps_incident_open_while_health_still_bad(): void
    {
        IntegrationHealthChanged::dispatch($this->integration, HealthStatus::Healthy, HealthStatus::Degraded);
        $this->integration->update(['health_status' => HealthStatus::Degraded]);

        CircuitClosed::dispatch($this->integration, 'half_open_probe_succeeded');

        $this->assertTrue($this->reloaded()->has_open_incident);
    }

    public function test_circuit_close_closes_incident_once_health_is_healthy(): void
    {
        IntegrationHealthChanged::dispatch($this->integration, HealthStatus::Healthy, HealthStatus::Degraded);
        $this->integration->update(['health_status' => HealthStatus::Healthy]);

        CircuitClosed::dispatch($this->integration, 'half_open_probe_succeeded');

        $this->assertFalse($this->reloaded()->has_open_incident);
        $this->assertSame(IntegrationIncident::STATUS_CLOSED, IntegrationIncident::query()->firstOrFail()->status);
    }

    public function test_current_incident_and_scopes(): void
    {
        $this->assertNull($this->reloaded()->current_incident);
        $this->assertFalse($this->reloaded()->has_open_incident);

        IntegrationHealthChanged::dispatch($this->integration, HealthStatus::Healthy, HealthStatus::Degraded);

        $current = $this->reloaded()->current_incident;
        $this->assertNotNull($current);
        $this->assertTrue($this->reloaded()->has_open_incident);

        $this->assertSame(1, IntegrationIncident::query()->open()->count());
        $this->assertSame(0, IntegrationIncident::query()->closed()->count());
        $this->assertSame(1, IntegrationIncident::query()->forIntegration($this->integration->id)->count());
        $this->assertSame(1, IntegrationIncident::query()->since(now()->subHour())->count());
    }

    public function test_disabled_via_config_records_nothing(): void
    {
        config(['integrations.observability.incidents_enabled' => false]);

        IntegrationHealthChanged::dispatch($this->integration, HealthStatus::Healthy, HealthStatus::Degraded);

        $this->assertDatabaseCount('integration_incidents', 0);
    }

    /**
     * A clean instance so the `incidents` relation reflects current DB state
     * (the subscriber writes via its own instances; ours would be stale).
     */
    private function reloaded(): Integration
    {
        return Integration::findOrFail($this->integration->id);
    }
}
