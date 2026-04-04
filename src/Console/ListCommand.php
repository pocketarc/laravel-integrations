<?php

declare(strict_types=1);

namespace Integrations\Console;

use Illuminate\Console\Command;
use Integrations\Models\Integration;

class ListCommand extends Command
{
    #[\Override]
    protected $signature = 'integrations:list';

    #[\Override]
    protected $description = 'List all integrations with their health and sync status.';

    public function handle(): int
    {
        $integrations = Integration::all();

        if ($integrations->isEmpty()) {
            $this->info('No integrations registered.');

            return self::SUCCESS;
        }

        $rows = $integrations->map(function (Integration $integration): array {
            $totalRequests = $integration->requests()->recent(24)->count();
            $failedRequests = $integration->requests()->recent(24)->failed()->count();
            $errorRate = $totalRequests > 0
                ? round(($failedRequests / $totalRequests) * 100, 1).'%'
                : 'N/A';

            return [
                $integration->name,
                $integration->provider,
                $integration->health_status->value,
                $integration->is_active ? 'Yes' : 'No',
                $integration->last_synced_at?->format('Y-m-d H:i:s') ?? 'Never',
                (string) $totalRequests,
                $errorRate,
            ];
        })->all();

        $this->table(
            ['Name', 'Provider', 'Health', 'Active', 'Last Synced', 'Requests (24h)', 'Error Rate'],
            $rows,
        );

        return self::SUCCESS;
    }
}
