<?php

declare(strict_types=1);

namespace Integrations\Tests\Unit;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Integrations\Enums\HealthStatus;
use Integrations\Events\CircuitClosed;
use Integrations\Events\CircuitOpened;
use Integrations\Events\IntegrationDisabled;
use Integrations\Events\IntegrationHealthChanged;
use Integrations\Events\SyncBecameStale;
use Integrations\Events\SyncStalenessRecovered;
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

    public function test_circuit_only_incident_opens_and_closes_via_the_circuit(): void
    {
        // A breaker can trip while health is still Healthy (the rate strategy and
        // the consecutive-failure health counter can disagree). Health never
        // transitions, so no IntegrationHealthChanged(→Healthy) ever fires — the
        // circuit-close is the only thing that can close this incident.
        CircuitOpened::dispatch($this->integration, 'threshold_reached');
        $this->assertTrue($this->reloaded()->has_open_incident);

        CircuitClosed::dispatch($this->integration, 'half_open_probe_succeeded');
        $this->assertFalse($this->reloaded()->has_open_incident);
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

    public function test_incident_accessors_do_not_lazy_load_the_full_history(): void
    {
        IntegrationHealthChanged::dispatch($this->integration, HealthStatus::Healthy, HealthStatus::Degraded);

        Model::preventLazyLoading(true);

        try {
            // Not eager-loaded: the accessor runs a narrow query rather than
            // lazy-loading the relation (which would throw here).
            $fresh = Integration::findOrFail($this->integration->id);
            $this->assertTrue($fresh->has_open_incident);
            $this->assertNotNull($fresh->current_incident);

            // Eager-loaded: reads the collection, no lazy load.
            $eager = Integration::query()->with('incidents')->findOrFail($this->integration->id);
            $this->assertTrue($eager->has_open_incident);
            $this->assertNotNull($eager->current_incident);
        } finally {
            Model::preventLazyLoading(false);
        }
    }

    public function test_sync_staleness_opens_an_incident(): void
    {
        SyncBecameStale::dispatch($this->staleIntegration(), 1_036_800);

        $incident = IntegrationIncident::query()->forIntegration($this->integration->id)->open()->first();

        $this->assertNotNull($incident);
        $this->assertSame(IntegrationIncident::SOURCE_SYNC, $incident->source);
        $this->assertSame('sync_stale', $incident->reason);
    }

    public function test_a_health_recovery_does_not_close_an_incident_while_the_sync_is_stale(): void
    {
        $stale = $this->staleIntegration();
        SyncBecameStale::dispatch($stale, 1_036_800);

        IntegrationHealthChanged::dispatch($stale, HealthStatus::Degraded, HealthStatus::Healthy);

        $this->assertTrue($this->reloaded()->has_open_incident);
    }

    public function test_the_incident_closes_once_the_sync_recovers(): void
    {
        $stale = $this->staleIntegration();
        SyncBecameStale::dispatch($stale, 1_036_800);

        $stale->markSynced(now());
        SyncStalenessRecovered::dispatch($stale->refresh());

        $this->assertFalse($this->reloaded()->has_open_incident);
    }

    public function test_prune_does_not_auto_close_a_stale_integrations_incident(): void
    {
        $stale = $this->staleIntegration();
        SyncBecameStale::dispatch($stale, 1_036_800);

        IntegrationIncident::query()
            ->forIntegration($this->integration->id)
            ->update(['opened_at' => now()->subDays(30)]);

        $this->artisan('integrations:prune')->assertSuccessful();

        $this->assertTrue($this->reloaded()->has_open_incident);
    }

    public function test_prune_leaves_an_incident_open_while_the_staleness_marker_is_set(): void
    {
        // Closing here would strand the incident: openStaleness() only fires on
        // a null marker, so nothing would reopen one for the same episode.
        $stale = $this->staleIntegration();
        SyncBecameStale::dispatch($stale, 1_036_800);
        $this->integration->update(['sync_stale_alerted_at' => now()]);

        // Fresh again by the accessor, so only the marker holds the sweep off.
        $this->integration->update(['last_synced_at' => now()]);

        IntegrationIncident::query()
            ->forIntegration($this->integration->id)
            ->update(['opened_at' => now()->subDays(30)]);

        $this->artisan('integrations:prune')->assertSuccessful();

        $this->assertTrue($this->reloaded()->has_open_incident);
    }

    public function test_prune_rechecks_the_marker_before_closing(): void
    {
        // The scheduler runs every minute, so it can mark an integration stale
        // between the sweep selecting candidates and closing their incidents.
        $stale = $this->staleIntegration();
        SyncBecameStale::dispatch($stale, 1_036_800);

        // Fresh and unmarked, so the sweep selects it.
        $this->integration->update([
            'last_synced_at' => now(),
            'sync_stale_alerted_at' => null,
        ]);

        IntegrationIncident::query()
            ->forIntegration($this->integration->id)
            ->update(['opened_at' => now()->subDays(30)]);

        $integrationId = $this->integration->id;
        $table = $this->integration->getTable();
        $marked = false;

        // Write the marker the moment the candidate query has run, standing in
        // for a concurrent integrations:sync.
        DB::listen(function (QueryExecuted $query) use (&$marked, $integrationId, $table): void {
            if ($marked || ! str_contains($query->sql, 'sync_stale_alerted_at')) {
                return;
            }

            $marked = true;

            DB::table($table)->where('id', $integrationId)->update(['sync_stale_alerted_at' => now()]);
        });

        $this->artisan('integrations:prune')->assertSuccessful();

        $this->assertTrue($marked, 'Expected the candidate query to read the staleness marker.');
        $this->assertTrue($this->reloaded()->has_open_incident);
    }

    private function staleIntegration(): Integration
    {
        $this->integration->update([
            'sync_interval_minutes' => 15,
            'last_synced_at' => now()->subDays(12),
            'health_status' => HealthStatus::Healthy,
        ]);

        return $this->integration->refresh();
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
