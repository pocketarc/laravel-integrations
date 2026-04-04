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

        $rows = $integrations->map(function (Integration $integration): array {
            $requests24h = $integration->requests()->recent(24)->count();
            $requests7d = $integration->requests()->recent(168)->count();
            $requests30d = $integration->requests()->recent(720)->count();

            $failed24h = $integration->requests()->recent(24)->failed()->count();
            $errorRate = $requests24h > 0
                ? round(($failed24h / $requests24h) * 100, 1).'%'
                : 'N/A';

            $avgLatency = $integration->requests()->recent(24)->successful()->avg('duration_ms');
            $avgLatencyStr = is_numeric($avgLatency) ? round((float) $avgLatency).'ms' : 'N/A';

            $cacheHits = (int) $integration->requests()->recent(24)->sum('cache_hits');
            $cacheRatio = ($requests24h + $cacheHits) > 0
                ? round(($cacheHits / ($requests24h + $cacheHits)) * 100, 1).'%'
                : 'N/A';

            $syncLogs = $integration->logs()->forOperation('sync')->recent(168);
            $syncSuccess = (clone $syncLogs)->where('status', 'success')->count();
            $syncPartial = (clone $syncLogs)->where('status', 'partial')->count();
            $syncFailed = (clone $syncLogs)->where('status', 'failed')->count();
            $syncStr = "{$syncSuccess}/{$syncPartial}/{$syncFailed}";

            return [
                $integration->name,
                (string) $requests24h,
                (string) $requests7d,
                (string) $requests30d,
                $errorRate,
                $avgLatencyStr,
                $cacheRatio,
                $syncStr,
            ];
        })->all();

        $this->table(
            ['Name', '24h', '7d', '30d', 'Err %', 'Avg Latency', 'Cache Hit %', 'Syncs (ok/partial/fail)'],
            $rows,
        );

        return self::SUCCESS;
    }
}
