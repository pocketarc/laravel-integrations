<?php

declare(strict_types=1);

namespace Integrations\Console;

use Illuminate\Console\Command;
use Integrations\Jobs\FinaliseSyncRun;
use Integrations\Models\IntegrationSyncItem;

class SkipSyncItemCommand extends Command
{
    protected $signature = 'integrations:skip-sync-item {id : The integration_sync_items row id}';

    protected $description = 'Mark a permanently-failed sync item as skipped so the cursor can advance past it.';

    public function handle(): int
    {
        $argument = $this->argument('id');

        if (! is_string($argument) || ! ctype_digit($argument) || (int) $argument <= 0) {
            $this->error('The id argument must be a positive integer integration_sync_items id.');

            return self::FAILURE;
        }

        $id = (int) $argument;

        $item = IntegrationSyncItem::query()->find($id);

        if ($item === null) {
            $this->error("Sync item #{$id} not found.");

            return self::FAILURE;
        }

        // Atomic FAILED -> SKIPPED transition: only update if the status is
        // still 'failed' at the moment of the UPDATE. Prevents a concurrent
        // queue:retry that flips the row out of 'failed' from being silently
        // overwritten by this skip.
        $updated = IntegrationSyncItem::query()
            ->whereKey($item->getKey())
            ->where('status', IntegrationSyncItem::STATUS_FAILED)
            ->update([
                'status' => IntegrationSyncItem::STATUS_SKIPPED,
                'completed_at' => now(),
            ]);

        if ($updated !== 1) {
            $currentStatus = IntegrationSyncItem::query()
                ->whereKey($item->getKey())
                ->value('status');
            $statusForMessage = is_string($currentStatus) ? $currentStatus : 'unknown';

            $this->error("Sync item #{$id} is '{$statusForMessage}', not 'failed'. Only failed items can be skipped.");

            return self::FAILURE;
        }

        $this->info("Sync item #{$id} marked as skipped.");

        if ($item->sync_log_id !== null) {
            FinaliseSyncRun::dispatchFor($item->integration_id, $item->sync_log_id, $item->integration?->provider);
            $this->info('Dispatched FinaliseSyncRun; the cursor will advance past this item once the run reconciles.');
        }

        return self::SUCCESS;
    }
}
