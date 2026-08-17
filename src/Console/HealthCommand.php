<?php

declare(strict_types=1);

namespace Integrations\Console;

use Illuminate\Console\Command;
use Integrations\CircuitBreaker;
use Integrations\Enums\HealthStatus;
use Integrations\Models\Integration;
use Symfony\Component\Console\Formatter\OutputFormatter;

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
        // Escape interpolated values that could contain `<...>` and be parsed
        // as console formatting: the operator-set name and the provider key.
        $name = OutputFormatter::escape($integration->name);
        $provider = OutputFormatter::escape($integration->provider);
        $this->info("=== {$name} ({$provider}) ===");

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
        $this->line('  Sync: '.$this->syncLabel($integration));

        $this->renderAuthenticatedUser($integration);

        $summary = $integration->failureSummary(now()->subHours(24));
        $total = $summary->totalRequests;
        $successful = $total - $summary->failedRequests;

        $this->line("  Requests (24h): {$total} total, {$successful} successful");
        $this->line('  Avg response time: '.($summary->avgSuccessfulDurationMs !== null ? round($summary->avgSuccessfulDurationMs).'ms' : 'N/A'));

        if ($summary->topErrors !== []) {
            $this->line('  Top errors:');
            foreach ($summary->topErrors as $topError) {
                // The logged error message is upstream/SDK text; escape it so
                // a `<...>` in it isn't parsed as console formatting.
                $truncated = OutputFormatter::escape(mb_substr($topError->message, 0, 80));
                $this->line("    [{$topError->count}x] {$truncated}");
            }
        }
    }

    private function syncLabel(Integration $integration): string
    {
        if ($integration->sync_interval_minutes === null) {
            return 'not scheduled';
        }

        $failures = $integration->consecutive_sync_failures;
        $suffix = $failures > 0 ? " ({$failures} failed run(s) in a row)" : '';

        if (! $integration->isSyncStale()) {
            return "on schedule{$suffix}";
        }

        return "<fg=red>STALE</> (expected every {$integration->sync_interval_minutes}m)".$suffix;
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
            // Escape provider-supplied values: a username/name/id containing
            // `<...>` would otherwise be parsed as console formatting tags.
            $id = OutputFormatter::escape($user->id);
            $label = $user->username ?? $user->name;
            $identity = $label !== null
                ? OutputFormatter::escape($label)." (id: {$id})"
                : "id: {$id}";
            $this->line("  Authenticated as: {$identity}");
        } catch (\Throwable $e) {
            $reason = OutputFormatter::escape(mb_substr($e->getMessage(), 0, 80));
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
