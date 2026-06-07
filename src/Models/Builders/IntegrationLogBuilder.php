<?php

declare(strict_types=1);

namespace Integrations\Models\Builders;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Integrations\Models\IntegrationLog;

/**
 * @template TModel of \Integrations\Models\IntegrationLog
 *
 * @extends Builder<TModel>
 */
class IntegrationLogBuilder extends Builder
{
    public function successful(): static
    {
        $this->where('status', IntegrationLog::STATUS_SUCCESS);

        return $this;
    }

    public function failed(): static
    {
        $this->where('status', IntegrationLog::STATUS_FAILED);

        return $this;
    }

    public function withStatus(string $status): static
    {
        $this->where('status', $status);

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

    public function since(CarbonInterface $since): static
    {
        $this->where('created_at', '>=', $since);

        return $this;
    }
}
