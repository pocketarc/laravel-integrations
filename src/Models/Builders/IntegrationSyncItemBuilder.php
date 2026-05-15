<?php

declare(strict_types=1);

namespace Integrations\Models\Builders;

use Illuminate\Database\Eloquent\Builder;
use Integrations\Models\IntegrationSyncItem;

/**
 * @template TModel of \Integrations\Models\IntegrationSyncItem
 *
 * @extends Builder<TModel>
 */
class IntegrationSyncItemBuilder extends Builder
{
    public function pending(): static
    {
        $this->where('status', IntegrationSyncItem::STATUS_PENDING);

        return $this;
    }

    public function processing(): static
    {
        $this->where('status', IntegrationSyncItem::STATUS_PROCESSING);

        return $this;
    }

    public function successful(): static
    {
        $this->where('status', IntegrationSyncItem::STATUS_SUCCESS);

        return $this;
    }

    public function failed(): static
    {
        $this->where('status', IntegrationSyncItem::STATUS_FAILED);

        return $this;
    }

    public function skipped(): static
    {
        $this->where('status', IntegrationSyncItem::STATUS_SKIPPED);

        return $this;
    }

    /**
     * Items that have not reached a terminal state. A sync run with any of
     * these is still in flight.
     */
    public function inFlight(): static
    {
        $this->whereIn('status', [
            IntegrationSyncItem::STATUS_PENDING,
            IntegrationSyncItem::STATUS_PROCESSING,
        ]);

        return $this;
    }

    public function forBatch(string $batchId): static
    {
        $this->where('batch_id', $batchId);

        return $this;
    }

    /**
     * Scope to one sync run. The `sync_log_id` is the run's identity from
     * the moment its rows are inserted, unlike `batch_id`, which is only
     * set once the Bus batch is dispatched.
     */
    public function forSyncLog(int $syncLogId): static
    {
        $this->where('sync_log_id', $syncLogId);

        return $this;
    }

    public function forIntegration(int $integrationId): static
    {
        $this->where('integration_id', $integrationId);

        return $this;
    }
}
