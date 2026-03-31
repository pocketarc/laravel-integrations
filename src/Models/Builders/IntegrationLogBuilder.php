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
        $this->where('status', 'success');

        return $this;
    }

    public function failed(): static
    {
        $this->where('status', 'failed');

        return $this;
    }

    public function forOperation(string $operation): static
    {
        $this->where('operation', $operation);

        return $this;
    }

    public function topLevel(): static
    {
        $this->whereNull('parent_id');

        return $this;
    }

    public function recent(int $hours = 24): static
    {
        $this->where('created_at', '>=', now()->subHours($hours));

        return $this;
    }
}
