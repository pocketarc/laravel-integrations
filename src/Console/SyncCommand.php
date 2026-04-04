<?php

declare(strict_types=1);

namespace Integrations\Console;

use Illuminate\Console\Command;
use Integrations\Contracts\HasScheduledSync;
use Integrations\Enums\HealthStatus;
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

            SyncIntegration::dispatch($integration->id)->onQueue($queue);
            $dispatched++;
        }

        if ($dispatched > 0) {
            $this->info("Dispatched {$dispatched} sync job(s).");
        }

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
        $nextAllowedSync = $integration->last_synced_at->addMinutes($effectiveInterval);

        return $nextAllowedSync->isFuture();
    }
}
