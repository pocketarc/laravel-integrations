<?php

declare(strict_types=1);

namespace Integrations\Console;

use Illuminate\Console\Command;
use Integrations\Contracts\HasScheduledSync;
use Integrations\Enums\HealthStatus;
use Integrations\Events\SyncBecameStale;
use Integrations\Events\SyncStalenessRecovered;
use Integrations\IntegrationManager;
use Integrations\Jobs\SyncIntegration;
use Integrations\Models\Integration;
use Integrations\Support\Config;

class SyncCommand extends Command
{
    protected $signature = 'integrations:sync';

    protected $description = 'Dispatch sync jobs for integrations that are due.';

    public function handle(IntegrationManager $manager): int
    {
        $integrations = Integration::dueForSync()->get();
        $dispatched = 0;

        foreach ($integrations as $integration) {
            if (! $manager->has($integration->provider)) {
                continue;
            }

            $provider = $manager->provider($integration->provider);

            if (! $provider instanceof HasScheduledSync) {
                continue;
            }

            // Apply health-based backoff
            if ($this->shouldSkipDueToHealth($integration)) {
                continue;
            }

            $queue = Config::syncQueue($integration->provider);

            SyncIntegration::dispatch($integration->id, Config::syncJobTimeout())->onQueue($queue);
            $dispatched++;
        }

        if ($dispatched > 0) {
            $this->info("Dispatched {$dispatched} sync job(s).");
        }

        $this->evaluateStaleness();

        return self::SUCCESS;
    }

    private function shouldSkipDueToHealth(Integration $integration): bool
    {
        if ($integration->health_status === HealthStatus::Healthy) {
            return false;
        }

        if ($integration->health_status === HealthStatus::Disabled) {
            return true;
        }

        if ($integration->sync_interval_minutes === null || $integration->last_synced_at === null) {
            return false;
        }

        $multiplier = match ($integration->health_status) {
            HealthStatus::Degraded => Config::degradedBackoff(),
            HealthStatus::Failing => Config::failingBackoff(),
        };

        $effectiveInterval = $integration->sync_interval_minutes * $multiplier;
        $nextAllowedSync = $integration->last_synced_at->copy()->addMinutes($effectiveInterval);

        return $nextAllowedSync->isFuture();
    }

    private function evaluateStaleness(): void
    {
        $scheduled = Integration::query()
            ->active()
            ->whereNotNull('sync_interval_minutes')
            ->get();

        foreach ($scheduled as $integration) {
            if ($integration->isSyncStale()) {
                $this->openStaleness($integration);

                continue;
            }

            $this->closeStaleness($integration);
        }
    }

    private function openStaleness(Integration $integration): void
    {
        $opened = Integration::query()
            ->whereKey($integration->id)
            ->whereNull('sync_stale_alerted_at')
            ->update(['sync_stale_alerted_at' => now()]);

        if ($opened !== 1) {
            return;
        }

        $staleness = $integration->syncStaleness() ?? 0;

        // The marker was written by a query builder, so the model still carries
        // the old value. A synchronous listener would otherwise read null.
        $integration->refresh();

        SyncBecameStale::dispatch($integration, $staleness);

        $this->warn("Sync is stale for {$integration->name}. Last clean sync: ".
            ($integration->last_synced_at?->diffForHumans() ?? 'never').'.');
    }

    private function closeStaleness(Integration $integration): void
    {
        $closed = Integration::query()
            ->whereKey($integration->id)
            ->whereNotNull('sync_stale_alerted_at')
            ->update(['sync_stale_alerted_at' => null]);

        if ($closed === 1) {
            $integration->refresh();

            SyncStalenessRecovered::dispatch($integration);
        }
    }
}
