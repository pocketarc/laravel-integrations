<?php

declare(strict_types=1);

namespace Integrations\Console;

use Illuminate\Console\Command;
use Integrations\Jobs\FinaliseSyncRun;
use Integrations\Models\Integration;

class AdvanceCursorCommand extends Command
{
    protected $signature = 'integrations:advance-cursor {integration : The integration id}';

    protected $description = 'Re-finalise any unreconciled sync runs for an integration, advancing the cursor past resolved items.';

    public function handle(): int
    {
        $argument = $this->argument('integration');

        if (! is_string($argument) || ! ctype_digit($argument) || (int) $argument <= 0) {
            $this->error('The integration argument must be a positive integer id.');

            return self::FAILURE;
        }

        $integrationId = (int) $argument;

        $integration = Integration::query()->find($integrationId);

        if ($integration === null) {
            $this->error("Integration #{$integrationId} not found.");

            return self::FAILURE;
        }

        // A sync run whose log is still "processing" hasn't been reconciled.
        // FinaliseSyncRun bails on its own if the run's items aren't all
        // terminal yet, so re-dispatching it is always safe.
        $processingLogs = $integration->logs()
            ->forOperation('sync')
            ->where('status', 'processing')
            ->get();

        if ($processingLogs->isEmpty()) {
            $this->info("No unreconciled sync runs for integration '{$integration->name}'.");

            return self::SUCCESS;
        }

        foreach ($processingLogs as $log) {
            FinaliseSyncRun::dispatchFor($integration->id, $log->id, $integration->provider);
        }

        $this->info("Dispatched FinaliseSyncRun for {$processingLogs->count()} unreconciled sync run(s).");

        return self::SUCCESS;
    }
}
