<?php

declare(strict_types=1);

namespace Integrations\Models\Builders;

use Illuminate\Database\Eloquent\Builder;

/**
 * @template TModel of \Integrations\Models\IntegrationLog
 *
 * @extends Builder<TModel>
 */
class IntegrationLogBuilder extends Builder
{
    public function successful(): static
    {
        return $this->where('status', 'success');
    }

    public function failed(): static
    {
        return $this->where('status', 'failed');
    }

    public function forOperation(string $operation): static
    {
        return $this->where('operation', $operation);
    }

    public function topLevel(): static
    {
        $this->whereNull('parent_id');

        return $this;
    }

    public function recent(int $hours = 24): static
    {
        return $this->where('created_at', '>=', now()->subHours($hours));
    }
}
