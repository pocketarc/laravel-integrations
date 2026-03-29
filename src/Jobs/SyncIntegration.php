<?php

declare(strict_types=1);

namespace Integrations\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Log;
use Integrations\Contracts\HasScheduledSync;
use Integrations\Models\Integration;
use Integrations\Support\Config;

class SyncIntegration implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public function __construct(
        public readonly int $integrationId,
    ) {}

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping("integration-sync-{$this->integrationId}"))->expireAfter(Config::syncLockTtl()),
        ];
    }

    public function handle(): void
    {
        $integration = Integration::find($this->integrationId);

        if ($integration === null || ! $integration->is_active) {
            return;
        }

        $provider = $integration->provider();

        if (! $provider instanceof HasScheduledSync) {
            return;
        }

        $startTime = hrtime(true);

        try {
            $result = $provider->sync($integration);

            $durationMs = (int) ((hrtime(true) - $startTime) / 1_000_000);

            $integration->markSynced($result->safeSyncedAt);
            $integration->logOperation(
                operation: 'sync',
                direction: 'inbound',
                status: $result->hasFailures() ? 'partial' : 'success',
                summary: "Scheduled sync completed: {$result->successCount} succeeded, {$result->failureCount} failed.",
                metadata: [
                    'success_count' => $result->successCount,
                    'failure_count' => $result->failureCount,
                ],
                durationMs: $durationMs,
            );
        } catch (\Throwable $e) {
            $durationMs = (int) ((hrtime(true) - $startTime) / 1_000_000);

            $integration->logOperation(
                operation: 'sync',
                direction: 'inbound',
                status: 'failed',
                error: $e->getMessage(),
                durationMs: $durationMs,
            );

            Log::error("Integration sync failed for '{$integration->name}': {$e->getMessage()}", [
                'integration_id' => $integration->id,
                'provider' => $integration->provider,
            ]);

            throw $e;
        }
    }
}
