<?php

declare(strict_types=1);

namespace Integrations\Sync;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Integrations\Models\Integration;
use Integrations\Models\IntegrationSyncItem;
use Integrations\Support\Config;

/**
 * Marks as failed the sync items that no queue job will ever pick up, so the
 * runs they belong to can finalise.
 *
 * Failed, never deleted: `FinaliseSyncRun` stops early on a run with no item
 * rows, so deleting a run's items would strand its log at `processing` forever.
 */
final class StaleItemReclaimer
{
    /**
     * @return list<int> `integration_logs` ids of the affected sync runs
     */
    public function reclaim(Integration $integration): array
    {
        $logIds = array_merge(
            $this->reclaimNeverDispatched($integration),
            $this->reclaimAbandoned($integration),
        );

        $logIds = array_values(array_unique($logIds));

        if ($logIds !== []) {
            Log::warning(sprintf(
                "Reclaimed abandoned sync items for integration '%s' across %d run(s): "
                .'no queue job remained to run them. Those runs can now finalise, and the '
                .'next sync re-enumerates the work.',
                $integration->name,
                count($logIds),
            ));
        }

        return $logIds;
    }

    /**
     * @return list<int>
     */
    private function reclaimNeverDispatched(Integration $integration): array
    {
        $candidates = IntegrationSyncItem::query()
            ->forIntegration($integration->id)
            ->inFlight()
            ->whereNull('batch_id')
            ->where('created_at', '<', now()->subSeconds(Config::syncJobTimeout()))
            ->get(['id', 'sync_log_id']);

        if ($candidates->isEmpty()) {
            return [];
        }

        $dispatched = $this->syncLogIdsWithABatch(
            $integration->id,
            $candidates->pluck('sync_log_id')->unique()->values()->all(),
        );

        $orphans = $candidates->whereNotIn('sync_log_id', $dispatched);

        return $this->failItems(
            $orphans->pluck('id')->all(),
            $orphans->pluck('sync_log_id')->all(),
            'Reclaimed by the framework: the sync run crashed before its queue batch was '
            .'dispatched, so no job would ever have processed this item. The next run '
            .'re-enumerates it. Do not skip it.',
        );
    }

    /**
     * @return list<int>
     */
    private function reclaimAbandoned(Integration $integration): array
    {
        $threshold = Config::syncItemReclaimAfter();

        $candidates = IntegrationSyncItem::query()
            ->forIntegration($integration->id)
            ->inFlight()
            ->where('created_at', '<', now()->subSeconds($threshold))
            ->get(['id', 'sync_log_id']);

        return $this->failItems(
            $candidates->pluck('id')->all(),
            $candidates->pluck('sync_log_id')->all(),
            sprintf(
                'Reclaimed by the framework: still in flight %d seconds after it was enqueued, '
                .'with no queue job behind it (a worker restart or a lost job). It never ran, so '
                .'the next sync re-enumerates it. Do not skip it.',
                $threshold,
            ),
        );
    }

    /**
     * @param  array<mixed>  $ids
     * @param  array<mixed>  $syncLogIds
     * @return list<int>
     */
    private function failItems(array $ids, array $syncLogIds, string $error): array
    {
        $ids = array_values(array_filter($ids, static fn (mixed $id): bool => is_int($id)));

        if ($ids === []) {
            return [];
        }

        IntegrationSyncItem::query()
            ->whereIn('id', $ids)
            ->whereIn('status', [
                IntegrationSyncItem::STATUS_PENDING,
                IntegrationSyncItem::STATUS_PROCESSING,
            ])
            ->update([
                'status' => IntegrationSyncItem::STATUS_FAILED,
                'completed_at' => now(),
                'error' => $error,
            ]);

        $logIds = [];

        foreach ($syncLogIds as $syncLogId) {
            if (is_int($syncLogId)) {
                $logIds[] = $syncLogId;
            }
        }

        return array_values(array_unique($logIds));
    }

    /**
     * Of the given sync-run log ids, the ones whose batch made it into
     * `job_batches` (so the batch was dispatched and its jobs exist).
     *
     * @param  array<int, mixed>  $syncLogIds
     * @return list<int>
     */
    private function syncLogIdsWithABatch(int $integrationId, array $syncLogIds): array
    {
        $logIdByName = [];
        foreach ($syncLogIds as $syncLogId) {
            if (is_int($syncLogId)) {
                $logIdByName["integration-sync-{$integrationId}-{$syncLogId}"] = $syncLogId;
            }
        }

        if ($logIdByName === []) {
            return [];
        }

        $connection = config('queue.batching.database');
        $table = config('queue.batching.table', 'job_batches');

        $names = DB::connection(is_string($connection) ? $connection : null)
            ->table(is_string($table) ? $table : 'job_batches')
            ->whereIn('name', array_keys($logIdByName))
            ->pluck('name');

        $dispatched = [];
        foreach ($names as $name) {
            if (is_string($name) && array_key_exists($name, $logIdByName)) {
                $dispatched[] = $logIdByName[$name];
            }
        }

        return $dispatched;
    }
}
