<?php

declare(strict_types=1);

namespace Integrations\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Bus\PendingDispatch;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;
use Integrations\Contracts\HasScheduledSync;
use Integrations\Events\SyncCompleted;
use Integrations\Models\Integration;
use Integrations\Models\IntegrationLog;
use Integrations\Models\IntegrationSyncItem;
use Integrations\Support\Config;
use Integrations\Sync\SyncResult;

/**
 * Reconciles a finished sync run: advances the integration's `sync_cursor`
 * when every item succeeded, finalises the parent log, and fires
 * `SyncCompleted`.
 *
 * The run's `integration_sync_items` rows are the source of truth, keyed
 * by `sync_log_id`, which is set the moment the rows are inserted (unlike
 * `batch_id`, which lands later and not at all under the `sync` queue
 * driver). Reading the rows rather than the Bus batch counters also keeps
 * this correct after a failed item is later retried: the batch's
 * `failedJobs` counter never un-increments.
 *
 * Dispatched from the batch's `finally` callback and, in the retry-catchup
 * case, from `ProcessSyncItem`. Idempotent: the cursor advance is monotonic
 * (never moves backward) and `SyncCompleted` only fires while the parent
 * log is still `processing`, so a second run is a harmless no-op.
 */
class FinaliseSyncRun implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public readonly int $tries;

    public function __construct(
        public readonly int $integrationId,
        public readonly int $syncLogId,
    ) {
        $this->tries = 10;
    }

    /**
     * Dispatch onto the same queue as the run's ProcessSyncItem jobs.
     * Reconciliation must never wait behind an unrelated queue's backlog: a
     * starved default queue once left every run unfinalised for days, parking
     * cursors while the scheduler re-synced the same window on repeat. Every
     * dispatch site goes through here so none can drift back to the default
     * queue.
     */
    public static function dispatchFor(int $integrationId, int $syncLogId, ?string $provider = null): PendingDispatch
    {
        return static::dispatch($integrationId, $syncLogId)
            ->onQueue(Config::syncItemQueue($provider));
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [10, 30, 60, 120, 300];
    }

    public function handle(): void
    {
        $integration = null;
        $result = null;

        DB::transaction(function () use (&$integration, &$result): void {
            [$integration, $result] = $this->reconcile();
        });

        if ($integration !== null && $result !== null) {
            SyncCompleted::dispatch($integration, $result);
        }
    }

    /**
     * @return array{0: Integration|null, 1: SyncResult|null}
     */
    private function reconcile(): array
    {
        $integration = Integration::query()->lockForUpdate()->find($this->integrationId);
        if ($integration === null) {
            return [null, null];
        }

        /** @var Collection<int, IntegrationSyncItem> $items */
        $items = IntegrationSyncItem::query()->forSyncLog($this->syncLogId)->get();

        // The `finally` callback only fires once every job has run, but a
        // self-trigger from ProcessSyncItem could race ahead. If anything is
        // empty or still in flight, bail; a later trigger will redo this.
        if ($items->isEmpty() || $items->contains(fn (IntegrationSyncItem $item): bool => ! $item->isTerminal())) {
            return [$integration, null];
        }

        $failed = $items->where('status', IntegrationSyncItem::STATUS_FAILED);
        $completed = $items->whereIn('status', [
            IntegrationSyncItem::STATUS_SUCCESS,
            IntegrationSyncItem::STATUS_SKIPPED,
        ]);

        if ($failed->isEmpty()) {
            $this->advanceCursor($integration, $completed);
            $integration->markSynced(now());
        }

        $result = $this->finaliseLog($integration, $completed->count(), $failed->count());

        return [$integration, $result];
    }

    /**
     * Finalises the parent log and returns the run's result, but only while
     * the log is still `processing`, so the run is reported exactly once even
     * if this job runs again (e.g. after a retried item completes the run).
     */
    private function finaliseLog(Integration $integration, int $successCount, int $failureCount): ?SyncResult
    {
        $log = IntegrationLog::query()->find($this->syncLogId);
        if ($log === null || $log->status !== IntegrationLog::STATUS_PROCESSING) {
            return null;
        }

        $log->update([
            'status' => match (true) {
                $failureCount === 0 => IntegrationLog::STATUS_SUCCESS,
                $successCount === 0 => IntegrationLog::STATUS_FAILED,
                default => IntegrationLog::STATUS_PARTIAL,
            },
            'summary' => sprintf('Sync completed: %d succeeded, %d failed.', $successCount, $failureCount),
            'metadata' => array_merge(
                is_array($log->metadata) ? $log->metadata : [],
                [
                    'success_count' => $successCount,
                    'failure_count' => $failureCount,
                ],
            ),
        ]);

        return new SyncResult($successCount, $failureCount, now(), $integration->sync_cursor);
    }

    /**
     * @param  Collection<int, IntegrationSyncItem>  $completed
     */
    private function advanceCursor(Integration $integration, Collection $completed): void
    {
        $provider = $integration->provider();
        if (! $provider instanceof HasScheduledSync) {
            return;
        }

        $checkpoints = array_values($completed->pluck('checkpoint_value')->all());
        $candidate = $provider->reduceCheckpoints($checkpoints);
        if ($candidate === null) {
            return;
        }

        $current = $integration->sync_cursor;
        if ($current !== null && $provider->reduceCheckpoints([$current, $candidate]) !== $candidate) {
            // The current cursor is already ahead: a later batch advanced
            // past this one. Don't move it backward.
            return;
        }

        $integration->updateSyncCursor($candidate);
    }
}
