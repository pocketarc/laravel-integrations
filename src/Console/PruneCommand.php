<?php

declare(strict_types=1);

namespace Integrations\Console;

use Illuminate\Console\Command;
use Integrations\Enums\HealthStatus;
use Integrations\Models\Integration;
use Integrations\Models\IntegrationIdempotencyKey;
use Integrations\Models\IntegrationIncident;
use Integrations\Models\IntegrationLog;
use Integrations\Models\IntegrationRequest;
use Integrations\Models\IntegrationSyncItem;
use Integrations\Support\Config;

class PruneCommand extends Command
{
    protected $signature = 'integrations:prune';

    protected $description = 'Delete old integration requests, logs, idempotency keys, completed sync items, and closed incidents based on retention settings.';

    public function handle(): int
    {
        $requestsDays = Config::pruningRequestsDays();
        $logsDays = Config::pruningLogsDays();
        $idempotencyKeysDays = Config::pruningIdempotencyKeysDays();
        $syncItemsDays = Config::pruningSyncItemsDays();
        $incidentsDays = Config::pruningIncidentsDays();
        $staleAfterDays = Config::incidentsStaleAfterDays();
        $chunkSize = Config::pruningChunkSize();

        $requestsPruned = $this->pruneTable(
            IntegrationRequest::class,
            $requestsDays,
            $chunkSize,
        );

        $logsPruned = $this->pruneTable(
            IntegrationLog::class,
            $logsDays,
            $chunkSize,
        );

        $idempotencyKeysPruned = $this->pruneTable(
            IntegrationIdempotencyKey::class,
            $idempotencyKeysDays,
            $chunkSize,
        );

        $syncItemsPruned = $this->pruneCompletedSyncItems($syncItemsDays, $chunkSize);

        $staleIncidentsClosed = $this->autoCloseStaleIncidents($staleAfterDays);
        $incidentsPruned = $this->pruneClosedIncidents($incidentsDays, $chunkSize);

        $this->info("Pruned {$requestsPruned} request(s) older than {$requestsDays} days.");
        $this->info("Pruned {$logsPruned} log(s) older than {$logsDays} days.");
        $this->info("Pruned {$idempotencyKeysPruned} idempotency key(s) older than {$idempotencyKeysDays} days.");
        $this->info("Pruned {$syncItemsPruned} completed sync item(s) older than {$syncItemsDays} days.");
        $this->info("Auto-closed {$staleIncidentsClosed} stale open incident(s) for healthy integrations.");
        $this->info("Pruned {$incidentsPruned} closed incident(s) older than {$incidentsDays} days.");

        return self::SUCCESS;
    }

    /**
     * Force-close incidents left open longer than the stale threshold for an
     * integration that is currently healthy. Covers the case where the closing
     * CircuitClosed event never fired because the cache state expired.
     */
    private function autoCloseStaleIncidents(int $days): int
    {
        $cutoff = now()->subDays($days);

        // Narrow to the integrations that actually have an incident old enough
        // to close before loading any of them: staleness is a computed value,
        // so those rows have to be read, and there are far fewer incidents past
        // the threshold than there are healthy integrations.
        $candidateIds = IntegrationIncident::query()
            ->open()
            ->where('opened_at', '<', $cutoff)
            ->distinct()
            ->pluck('integration_id');

        if ($candidateIds->isEmpty()) {
            return 0;
        }

        // A sync-stale integration is Healthy by health_status, so the sweep
        // would close the incident that records the staleness. Staleness can
        // also outlast the threshold, so age alone proves nothing here.
        $healthyIds = Integration::query()
            ->whereIn('id', $candidateIds)
            ->where('health_status', HealthStatus::Healthy->value)
            // Two staleness guards, covering different moments. The marker says
            // the integration was stale as of the last scheduler tick, and an
            // incident closed while it is still set could never be reopened,
            // because openStaleness() only fires on a null marker. The accessor
            // then catches an integration that has gone stale since that tick.
            ->whereNull('sync_stale_alerted_at')
            ->get(['id', 'is_active', 'sync_interval_minutes', 'last_synced_at', 'created_at'])
            ->reject(static fn (Integration $integration): bool => $integration->isSyncStale())
            ->pluck('id')
            ->all();

        if ($healthyIds === []) {
            return 0;
        }

        return IntegrationIncident::query()
            ->open()
            ->where('opened_at', '<', $cutoff)
            ->whereIn('integration_id', $healthyIds)
            ->update([
                'status' => IntegrationIncident::STATUS_CLOSED,
                'closed_at' => now(),
            ]);
    }

    /**
     * Prune closed incidents only, by closed_at. Open incidents are live state
     * and are never deleted.
     */
    private function pruneClosedIncidents(int $days, int $chunkSize): int
    {
        $cutoff = now()->subDays($days);
        $totalDeleted = 0;

        do {
            $ids = IntegrationIncident::query()
                ->closed()
                ->where('closed_at', '<', $cutoff)
                ->limit($chunkSize)
                ->pluck('id');

            if ($ids->isEmpty()) {
                break;
            }

            /** @var int $deleted */
            $deleted = IntegrationIncident::query()->whereIn('id', $ids)->delete();
            $totalDeleted += $deleted;
        } while ($ids->count() >= $chunkSize);

        return $totalDeleted;
    }

    /**
     * Prune completed sync items only. Rows still in "failed" status are
     * kept indefinitely so an operator can find and recover them.
     */
    private function pruneCompletedSyncItems(int $days, int $chunkSize): int
    {
        $cutoff = now()->subDays($days);
        $totalDeleted = 0;

        do {
            $ids = IntegrationSyncItem::query()
                ->whereIn('status', [
                    IntegrationSyncItem::STATUS_SUCCESS,
                    IntegrationSyncItem::STATUS_SKIPPED,
                ])
                ->where('created_at', '<', $cutoff)
                ->limit($chunkSize)
                ->pluck('id');

            if ($ids->isEmpty()) {
                break;
            }

            /** @var int $deleted */
            $deleted = IntegrationSyncItem::query()->whereIn('id', $ids)->delete();
            $totalDeleted += $deleted;
        } while ($ids->count() >= $chunkSize);

        return $totalDeleted;
    }

    /**
     * @param  class-string<IntegrationRequest|IntegrationLog|IntegrationIdempotencyKey>  $modelClass
     */
    private function pruneTable(string $modelClass, int $days, int $chunkSize): int
    {
        $cutoff = now()->subDays($days);
        $totalDeleted = 0;

        do {
            $ids = $modelClass::where('created_at', '<', $cutoff)
                ->limit($chunkSize)
                ->pluck('id');

            if ($ids->isEmpty()) {
                break;
            }

            /** @var int $deleted */
            $deleted = $modelClass::whereIn('id', $ids)->delete();
            $totalDeleted += $deleted;
        } while ($ids->count() >= $chunkSize);

        return $totalDeleted;
    }
}
