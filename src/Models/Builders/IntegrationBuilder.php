<?php

declare(strict_types=1);

namespace Integrations\Models\Builders;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * @template TModel of \Integrations\Models\Integration
 *
 * @extends Builder<TModel>
 */
class IntegrationBuilder extends Builder
{
    public function active(): static
    {
        return $this->where('is_active', true);
    }

    public function forProvider(string $provider): static
    {
        return $this->where('provider', $provider);
    }

    public function ownedBy(Model $owner): static
    {
        return $this->where('owner_type', $owner->getMorphClass())
            ->where('owner_id', $owner->getKey());
    }

    public function dueForSync(): static
    {
        $this->active()
            ->whereNotNull('sync_interval_minutes')
            ->where(function (Builder $q): void {
                $q->whereNull('next_sync_at')
                    ->orWhere('next_sync_at', '<=', now());
            });

        return $this;
    }
}
