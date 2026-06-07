<?php

declare(strict_types=1);

namespace Integrations\Console;

use Illuminate\Console\Command;
use Integrations\Models\Integration;

class StatsCommand extends Command
{
    protected $signature = 'integrations:stats';

    protected $description = 'Show request and sync metrics for all integrations.';

    public function handle(): int
    {
        $integrations = Integration::all();

        if ($integrations->isEmpty()) {
            $this->info('No integrations registered.');

            return self::SUCCESS;
        }

        $rows = [];

        foreach ($integrations as $integration) {
            // The 24h failure-shape (totals, error rate, latency, per-operation
            // sync outcomes) comes from one summary so the CLI and consumers
            // agree on the numbers; the wider counts stay as cheap queries.
            $summary = $integration->failureSummary(now()->subHours(24));

            $requests24h = $summary->totalRequests;
            $requests7d = $integration->requests()->recent(168)->count();
            $requests30d = $integration->requests()->recent(720)->count();

            $errorRate = $requests24h > 0
                ? round($summary->failureRate(), 1).'%'
                : 'N/A';

            $avgLatencyStr = $summary->avgSuccessfulDurationMs !== null
                ? round($summary->avgSuccessfulDurationMs).'ms'
                : 'N/A';

            $cacheHits = (int) $integration->requests()->recent(24)->sum('cache_hits');
            $cacheRatio = ($requests24h + $cacheHits) > 0
                ? round(($cacheHits / ($requests24h + $cacheHits)) * 100, 1).'%'
                : 'N/A';

            $sync = $summary->operations['sync'] ?? null;
            $syncStr = $sync !== null
                ? "{$sync->successful}/{$sync->partial}/{$sync->failed}"
                : '0/0/0';

            $rows[] = [
                $integration->name,
                (string) $requests24h,
                (string) $requests7d,
                (string) $requests30d,
                $errorRate,
                $avgLatencyStr,
                $cacheRatio,
                $syncStr,
            ];
        }

        $this->table(
            ['Name', '24h', '7d', '30d', 'Err %', 'Avg Latency', 'Cache Hit %', 'Syncs (ok/partial/fail)'],
            $rows,
        );

        return self::SUCCESS;
    }
}
