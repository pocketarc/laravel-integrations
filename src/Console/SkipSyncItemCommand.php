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

        if (! is_string($argument) || ! ctype_digit($argument)) {
            $this->error('The id argument must be a positive integer integration_sync_items id.');

            return self::FAILURE;
        }

        $id = (int) $argument;

        $item = IntegrationSyncItem::query()->find($id);

        if ($item === null) {
            $this->error("Sync item #{$id} not found.");

            return self::FAILURE;
        }

        if ($item->status !== IntegrationSyncItem::STATUS_FAILED) {
            $this->error("Sync item #{$id} is '{$item->status}', not 'failed'. Only failed items can be skipped.");

            return self::FAILURE;
        }

        $item->update([
            'status' => IntegrationSyncItem::STATUS_SKIPPED,
            'completed_at' => now(),
        ]);

        $this->info("Sync item #{$id} marked as skipped.");

        if ($item->sync_log_id !== null) {
            FinaliseSyncRun::dispatch($item->integration_id, $item->sync_log_id);
            $this->info('Dispatched FinaliseSyncRun; the cursor will advance past this item once the run reconciles.');
        }

        return self::SUCCESS;
    }
}
