<?php

declare(strict_types=1);

namespace Integrations\Console;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Integrations\Models\IntegrationLog;
use Integrations\Models\IntegrationRequest;

class PruneCommand extends Command
{
    protected $signature = 'integrations:prune';

    protected $description = 'Delete old integration requests and logs based on retention settings.';

    public function handle(): int
    {
        /** @var int $requestsDays */
        $requestsDays = config('integrations.pruning.requests_days', 90);
        /** @var int $logsDays */
        $logsDays = config('integrations.pruning.logs_days', 365);
        /** @var int $chunkSize */
        $chunkSize = config('integrations.pruning.chunk_size', 1000);

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

        $this->info("Pruned {$requestsPruned} request(s) older than {$requestsDays} days.");
        $this->info("Pruned {$logsPruned} log(s) older than {$logsDays} days.");

        return self::SUCCESS;
    }

    /**
     * @param  class-string<Model>  $modelClass
     */
    private function pruneTable(string $modelClass, int $days, int $chunkSize): int
    {
        $cutoff = now()->subDays($days);
        $totalDeleted = 0;

        do {
            /** @var int $deleted */
            $deleted = $modelClass::where('created_at', '<', $cutoff)
                ->limit($chunkSize)
                ->delete();

            $totalDeleted += $deleted;
        } while ($deleted >= $chunkSize);

        return $totalDeleted;
    }
}
