<?php

declare(strict_types=1);

namespace Integrations\Console;

use Illuminate\Console\Command;
use Integrations\Models\IntegrationIdempotencyKey;
use Integrations\Models\IntegrationLog;
use Integrations\Models\IntegrationRequest;
use Integrations\Support\Config;

class PruneCommand extends Command
{
    protected $signature = 'integrations:prune';

    protected $description = 'Delete old integration requests, logs, and idempotency keys based on retention settings.';

    public function handle(): int
    {
        $requestsDays = Config::pruningRequestsDays();
        $logsDays = Config::pruningLogsDays();
        $idempotencyKeysDays = Config::pruningIdempotencyKeysDays();
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

        $this->info("Pruned {$requestsPruned} request(s) older than {$requestsDays} days.");
        $this->info("Pruned {$logsPruned} log(s) older than {$logsDays} days.");
        $this->info("Pruned {$idempotencyKeysPruned} idempotency key(s) older than {$idempotencyKeysDays} days.");

        return self::SUCCESS;
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
