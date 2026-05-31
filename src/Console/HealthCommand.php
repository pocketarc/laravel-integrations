<?php

declare(strict_types=1);

namespace Integrations\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Integrations\CircuitBreaker;
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
            $this->renderIntegration($integration);
        }

        $this->newLine();

        return self::SUCCESS;
    }

    private function renderIntegration(Integration $integration): void
    {
        $this->newLine();
        $this->info("=== {$integration->name} ({$integration->provider}) ===");

        $healthColor = match ($integration->health_status) {
            HealthStatus::Healthy => 'green',
            HealthStatus::Degraded => 'yellow',
            HealthStatus::Failing => 'red',
            HealthStatus::Disabled => 'magenta',
        };

        $this->line("  Health: <fg={$healthColor}>{$integration->health_status->value}</>");
        $this->line('  Circuit: '.$this->circuitLabel($integration));
        $this->line("  Consecutive failures: {$integration->consecutive_failures}");
        $this->line('  Last error: '.($integration->last_error_at?->diffForHumans() ?? 'None'));
        $this->line('  Last synced: '.($integration->last_synced_at?->diffForHumans() ?? 'Never'));

        $this->renderAuthenticatedUser($integration);

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
            ->toBase()
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

    /**
     * Show the account the integration authenticates as, for providers that
     * can resolve it. Cached briefly so a report over many integrations
     * doesn't fan out a live call each. A failure here (open circuit, upstream
     * error) degrades to "unknown" rather than aborting the whole report.
     */
    private function renderAuthenticatedUser(Integration $integration): void
    {
        try {
            $supported = $integration->supportsAuthenticatedUser();
        } catch (\Throwable) {
            // Provider not registered or unresolvable: can't tell, so skip
            // the line rather than letting it abort the whole report.
            return;
        }

        if (! $supported) {
            return;
        }

        try {
            $user = $integration->authenticatedUser(cacheFor: now()->addHour());
            $label = $user->username ?? $user->name;
            $identity = $label !== null ? "{$label} (id: {$user->id})" : "id: {$user->id}";
            $this->line("  Authenticated as: {$identity}");
        } catch (\Throwable $e) {
            $reason = mb_substr($e->getMessage(), 0, 80);
            $this->line("  Authenticated as: <fg=yellow>unknown</> ({$reason})");
        }
    }

    /**
     * The breaker's effective state for display: an active override if set,
     * otherwise the live state-machine state from cache.
     */
    private function circuitLabel(Integration $integration): string
    {
        $override = $integration->effectiveCircuitOverride();
        if ($override !== null) {
            return $override->value;
        }

        return (new CircuitBreaker($integration))->inspect()['state'];
    }
}
