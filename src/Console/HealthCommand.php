<?php

declare(strict_types=1);

namespace Integrations\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Integrations\Enums\HealthStatus;
use Integrations\Models\Integration;

class HealthCommand extends Command
{
    protected $signature = 'integrations:health';

    protected $description = 'Show detailed health report for all integrations.';

    public function handle(): int
    {
        $integrations = Integration::all();

        if ($integrations->isEmpty()) {
            $this->info('No integrations registered.');

            return self::SUCCESS;
        }

        foreach ($integrations as $integration) {
            $this->newLine();
            $this->info("=== {$integration->name} ({$integration->provider}) ===");

            $healthColor = match ($integration->health_status) {
                HealthStatus::Healthy => 'green',
                HealthStatus::Degraded => 'yellow',
                HealthStatus::Failing => 'red',
                HealthStatus::Disabled => 'magenta',
            };

            $this->line("  Health: <fg={$healthColor}>{$integration->health_status->value}</>");
            $this->line("  Consecutive failures: {$integration->consecutive_failures}");
            $this->line('  Last error: '.($integration->last_error_at?->diffForHumans() ?? 'None'));
            $this->line('  Last synced: '.($integration->last_synced_at?->diffForHumans() ?? 'Never'));

            $recentRequests = $integration->requests()->recent(24);
            $total = $recentRequests->count();
            $successful = (clone $recentRequests)->successful()->count();
            $avgDuration = (clone $recentRequests)->successful()->avg('duration_ms');

            $this->line("  Requests (24h): {$total} total, {$successful} successful");
            $this->line('  Avg response time: '.(is_numeric($avgDuration) ? round((float) $avgDuration).'ms' : 'N/A'));

            $jsonExpr = match (DB::getDriverName()) {
                'pgsql' => "error->>'message'",
                'sqlite' => "json_extract(error, '$.message')",
                default => "JSON_UNQUOTE(JSON_EXTRACT(error, '$.message'))",
            };

            $topErrors = $integration->requests()
                ->recent(24)
                ->failed()
                ->selectRaw("{$jsonExpr} as error_message, COUNT(*) as count")
                ->groupByRaw("{$jsonExpr}")
                ->orderByDesc('count')
                ->limit(3)
                ->pluck('count', 'error_message');

            if ($topErrors->isNotEmpty()) {
                $this->line('  Top errors:');
                foreach ($topErrors as $message => $count) {
                    $truncated = mb_substr((string) $message, 0, 80);
                    $countStr = is_scalar($count) ? (string) $count : '?';
                    $this->line("    [{$countStr}x] {$truncated}");
                }
            }
        }

        $this->newLine();

        return self::SUCCESS;
    }
}
