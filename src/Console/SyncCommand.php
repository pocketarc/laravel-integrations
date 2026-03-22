<?php

declare(strict_types=1);

namespace Integrations\Console;

use Illuminate\Console\Command;
use Integrations\Contracts\HasScheduledSync;
use Integrations\IntegrationManager;
use Integrations\Jobs\SyncIntegration;
use Integrations\Models\Integration;

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

            /** @var string $queue */
            $queue = config('integrations.sync.queue', 'default');

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
        if ($integration->health_status === 'healthy') {
            return false;
        }

        if ($integration->sync_interval_minutes === null || $integration->last_synced_at === null) {
            return false;
        }

        /** @var int $degradedBackoff */
        $degradedBackoff = config('integrations.health.degraded_backoff', 2);
        /** @var int $failingBackoff */
        $failingBackoff = config('integrations.health.failing_backoff', 10);

        $multiplier = match ($integration->health_status) {
            'degraded' => $degradedBackoff,
            'failing' => $failingBackoff,
            default => 1,
        };

        $effectiveInterval = $integration->sync_interval_minutes * $multiplier;
        $nextAllowedSync = $integration->last_synced_at->addMinutes($effectiveInterval);

        return $nextAllowedSync->isFuture();
    }
}
