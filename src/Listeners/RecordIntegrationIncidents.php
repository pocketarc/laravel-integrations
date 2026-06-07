<?php

declare(strict_types=1);

namespace Integrations\Listeners;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\DB;
use Integrations\Enums\HealthStatus;
use Integrations\Events\CircuitClosed;
use Integrations\Events\CircuitOpened;
use Integrations\Events\IntegrationDisabled;
use Integrations\Events\IntegrationHealthChanged;
use Integrations\Models\Integration;
use Integrations\Models\IntegrationIncident;
use Integrations\Support\Config;

/**
 * Records a durable incident audit from the package's own health and circuit
 * state-change events. One open incident per integration: health degradation
 * and circuit trips fold into the same row (tracking peak severity), and it
 * closes on recovery. Runs synchronously so the audit stays consistent with
 * the state change; flapping is collapsed under a row lock.
 *
 * Operator overrides (forced_open / forced_closed) are deliberate actions, not
 * detected failures, so they neither open nor close an incident.
 */
class RecordIntegrationIncidents
{
    /**
     * @return array<class-string, string>
     */
    public function subscribe(Dispatcher $events): array
    {
        return [
            IntegrationHealthChanged::class => 'onHealthChanged',
            IntegrationDisabled::class => 'onDisabled',
            CircuitOpened::class => 'onCircuitOpened',
            CircuitClosed::class => 'onCircuitClosed',
        ];
    }

    public function onHealthChanged(IntegrationHealthChanged $event): void
    {
        if (! Config::incidentsEnabled()) {
            return;
        }

        if ($event->newStatus === HealthStatus::Healthy) {
            $this->closeOpen($event->integration->id);

            return;
        }

        $this->openOrEscalate(
            $event->integration->id,
            IntegrationIncident::SOURCE_HEALTH,
            'health_'.$event->newStatus->value,
            $event->newStatus,
        );
    }

    public function onDisabled(IntegrationDisabled $event): void
    {
        if (! Config::incidentsEnabled()) {
            return;
        }

        // Auto-disable fires this and IntegrationHealthChanged(→Disabled), so
        // both reach openOrEscalate for the same transition. The row lock makes
        // the second a no-op escalation, collapsing them to one incident.
        $this->openOrEscalate(
            $event->integration->id,
            IntegrationIncident::SOURCE_HEALTH,
            'disabled',
            HealthStatus::Disabled,
        );
    }

    public function onCircuitOpened(CircuitOpened $event): void
    {
        if (! Config::incidentsEnabled() || $event->reason === 'forced_open') {
            return;
        }

        // An open breaker is short-circuiting traffic: at least as bad as
        // Failing, but it never auto-disables, so it tops out there.
        $this->openOrEscalate(
            $event->integration->id,
            IntegrationIncident::SOURCE_CIRCUIT,
            $event->reason,
            HealthStatus::Failing,
        );
    }

    public function onCircuitClosed(CircuitClosed $event): void
    {
        if (! Config::incidentsEnabled() || $event->reason === 'forced_closed') {
            return;
        }

        // Health is the durable source of truth for "recovered". Only let a
        // circuit-close clear the incident once health itself is back to
        // Healthy, so a probe success doesn't hide a still-degraded integration.
        $integration = Integration::query()->find($event->integration->id);

        if ($integration?->health_status === HealthStatus::Healthy) {
            $this->closeOpen($event->integration->id);
        }
    }

    private function openOrEscalate(int $integrationId, string $source, string $reason, HealthStatus $severity): void
    {
        DB::transaction(function () use ($integrationId, $source, $reason, $severity): void {
            $open = IntegrationIncident::query()
                ->forIntegration($integrationId)
                ->open()
                ->lockForUpdate()
                ->first();

            $lastErrorAt = Integration::query()->find($integrationId)?->last_error_at;

            if ($open === null) {
                IntegrationIncident::query()->create([
                    'integration_id' => $integrationId,
                    'status' => IntegrationIncident::STATUS_OPEN,
                    'source' => $source,
                    'reason' => $reason,
                    'peak_severity' => $severity,
                    'opened_at' => now(),
                    'last_error_at' => $lastErrorAt,
                ]);

                return;
            }

            // Already open: collapse into it. Raise the peak if this is worse,
            // and refresh the recency marker.
            $updates = ['last_error_at' => $lastErrorAt ?? $open->last_error_at];

            if ($severity->severity() > $open->peak_severity->severity()) {
                $updates['peak_severity'] = $severity;
            }

            $open->update($updates);
        });
    }

    private function closeOpen(int $integrationId): void
    {
        DB::transaction(function () use ($integrationId): void {
            $open = IntegrationIncident::query()
                ->forIntegration($integrationId)
                ->open()
                ->lockForUpdate()
                ->first();

            if ($open === null) {
                return;
            }

            $open->update([
                'status' => IntegrationIncident::STATUS_CLOSED,
                'closed_at' => now(),
            ]);
        });
    }
}
