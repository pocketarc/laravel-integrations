<?php

declare(strict_types=1);

namespace Integrations\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;
use Integrations\Contracts\HasIncrementalSync;
use Integrations\Contracts\HasScheduledSync;
use Integrations\Events\SyncCompleted;
use Integrations\Models\Integration;
use Integrations\Models\IntegrationLog;
use Integrations\Models\IntegrationSyncItem;
use Integrations\Support\Config;
use Integrations\Support\IntegrationContext;
use Integrations\Sync\StaleItemReclaimer;
use Integrations\Sync\SyncResult;
use Integrations\Sync\SyncSession;
use RuntimeException;
use Throwable;

use function Safe\json_encode;

/**
 * Runs one sync for an integration. The provider enumerates the items to
 * sync into a `SyncSession`; this job turns them into `integration_sync_items`
 * rows and a `Bus::batch` of `ProcessSyncItem` jobs, then returns. Cursor
 * advancement and log finalisation happen asynchronously once the batch
 * finishes; see `FinaliseSyncRun`.
 *
 * The job itself stays short: the long-running work is the per-item jobs,
 * not this one. `WithoutOverlapping` still guards it, and a preflight check
 * refuses to dispatch a second batch while a previous one is still in flight.
 */
class SyncIntegration implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public function __construct(
        public readonly int $integrationId,
        public readonly int $timeout = 1800,
    ) {}

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping("integration-sync-{$this->integrationId}"))
                ->expireAfter(Config::syncLockTtl())
                ->dontRelease(),
        ];
    }

    public function handle(): void
    {
        $integration = Integration::query()->find($this->integrationId);

        if ($integration === null || ! $integration->is_active) {
            return;
        }

        $provider = $integration->provider();

        if (! $provider instanceof HasScheduledSync) {
            return;
        }

        if ($this->reclaimStaleItems($integration)) {
            return;
        }

        // A previous run's batch may still be processing items. Don't pile a
        // second batch on top of it; the next scheduled tick will try again.
        $inFlightSince = IntegrationSyncItem::query()
            ->forIntegration($integration->id)
            ->inFlight()
            ->min('created_at');

        if ($inFlightSince !== null) {
            Log::warning(sprintf(
                "Sync for integration '%s' skipped: a previous batch has been in flight since %s.",
                $integration->name,
                is_string($inFlightSince) ? $inFlightSince : 'an unknown time',
            ));

            return;
        }

        IntegrationContext::push($integration, 'sync');

        try {
            $this->runSync($integration, $provider);
        } catch (Throwable $e) {
            $integration->clearSyncContext();

            Log::error("Integration sync failed for '{$integration->name}': {$e->getMessage()}", [
                'integration_id' => $integration->id,
                'provider' => $integration->provider,
            ]);

            throw $e;
        } finally {
            IntegrationContext::clear();
        }
    }

    private function runSync(Integration $integration, HasScheduledSync $provider): void
    {
        $log = $integration->logOperation(
            operation: 'sync',
            direction: 'inbound',
            status: 'processing',
        );

        $session = new SyncSession($integration, $log->id);

        $integration->setSyncContext($log->id);

        if ($provider instanceof HasIncrementalSync) {
            $provider->syncIncremental($integration, $session);
        } else {
            $provider->sync($integration, $session);
        }

        $requestIds = $integration->clearSyncContext();
        $log->update(['metadata' => [
            'request_ids' => $requestIds,
            'duplicates_dropped' => $session->duplicatesDropped(),
        ]]);

        $this->warnAboutDuplicates($integration, $session);

        if ($session->isEmpty()) {
            $this->finaliseEmptyRun($integration, $log, $requestIds);

            return;
        }

        $this->dispatchBatch($integration, $log, $session);
    }

    private function warnAboutDuplicates(Integration $integration, SyncSession $session): void
    {
        $dropped = $session->duplicatesDropped();

        if ($dropped === 0) {
            return;
        }

        Log::warning(sprintf(
            "Sync for integration '%s' enumerated %d duplicate item(s), which were dropped. "
            .'The provider emitted the same external ID more than once in this run. A cursor '
            .'paged on an inclusive boundary is the usual cause.',
            $integration->name,
            $dropped,
        ));
    }

    /**
     * A run that enumerated nothing still completes; there's just no batch.
     * Finalise it inline rather than spinning up a no-op batch.
     *
     * @param  list<int>  $requestIds
     */
    private function finaliseEmptyRun(Integration $integration, IntegrationLog $log, array $requestIds): void
    {
        $integration->markSynced(now());

        $log->update([
            'status' => 'success',
            'summary' => 'Sync completed: 0 succeeded, 0 failed.',
            'metadata' => [
                'success_count' => 0,
                'failure_count' => 0,
                'request_ids' => $requestIds,
            ],
        ]);

        SyncCompleted::dispatch(
            $integration,
            new SyncResult(0, 0, now(), $integration->sync_cursor),
        );
    }

    private function dispatchBatch(Integration $integration, IntegrationLog $log, SyncSession $session): void
    {
        $items = $session->pendingItems();
        $itemCount = count($items);

        if ($itemCount > Config::syncMaxItemsPerBatch()) {
            Log::warning(sprintf(
                "Sync for integration '%s' enumerated %d items, above the configured "
                .'max_items_per_batch of %d. It will still be processed as a single batch; '
                .'consider narrowing the sync window or paging the provider more aggressively.',
                $integration->name,
                $itemCount,
                Config::syncMaxItemsPerBatch(),
            ));
        }

        // Insert the rows first (chunked, so a huge backfill doesn't build one
        // enormous INSERT), then re-read them in id order to pair each row
        // with the event it represents. sync_log_id uniquely scopes this run.
        $now = now();
        $rows = [];
        foreach ($items as $item) {
            $rows[] = [
                'integration_id' => $integration->id,
                'batch_id' => null,
                'sync_log_id' => $log->id,
                'event_class' => $item->event::class,
                'external_id' => $item->externalId,
                'checkpoint_value' => json_encode($item->checkpointValue),
                'status' => IntegrationSyncItem::STATUS_PENDING,
                'attempts' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            IntegrationSyncItem::query()->insert($chunk);
        }

        // Re-read the rows in id order (the order they were inserted, which is
        // the order of $items) to pair each row id with its event.
        /** @var list<int> $rowIds */
        $rowIds = IntegrationSyncItem::query()
            ->forSyncLog($log->id)
            ->orderBy('id')
            ->pluck('id')
            ->all();

        $jobs = [];
        foreach ($items as $index => $item) {
            // The rows were just inserted in this method and re-read by
            // sync_log_id, so every item has a row. A missing one means rows
            // vanished or an insert partially failed; abort loudly rather
            // than dispatching a silent subset of the run.
            $rowId = $rowIds[$index] ?? null;
            if ($rowId === null) {
                throw new RuntimeException(sprintf(
                    'Sync item row count mismatch for integration %d: no row for item %d of %d enumerated.',
                    $integration->id,
                    $index,
                    count($items),
                ));
            }

            $jobs[] = new ProcessSyncItem($rowId, $item->event, $log->id);
        }

        $integrationId = $integration->id;
        $syncLogId = $log->id;
        $providerKey = $integration->provider;

        $batch = Bus::batch($jobs)
            ->name("integration-sync-{$integrationId}-{$syncLogId}")
            ->onQueue(Config::syncItemQueue($providerKey))
            ->allowFailures()
            ->finally(function () use ($integrationId, $syncLogId, $providerKey): void {
                FinaliseSyncRun::dispatchFor($integrationId, $syncLogId, $providerKey);
            })
            ->dispatch();

        // Stamp the batch id for ops visibility (Horizon / job_batches
        // correlation). The core flow keys on sync_log_id, not this; under
        // the `sync` queue driver the jobs above have already run by now.
        IntegrationSyncItem::query()
            ->forSyncLog($syncLogId)
            ->update(['batch_id' => $batch->id]);
    }

    /**
     * @return bool whether anything was reclaimed
     */
    private function reclaimStaleItems(Integration $integration): bool
    {
        $logIds = (new StaleItemReclaimer)->reclaim($integration);

        foreach ($logIds as $logId) {
            FinaliseSyncRun::dispatchFor($integration->id, $logId, $integration->provider);
        }

        return $logIds !== [];
    }
}
