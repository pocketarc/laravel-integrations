<?php

declare(strict_types=1);

namespace Integrations\Models\Builders;

use Illuminate\Database\Eloquent\Builder;

/**
 * @template TModel of \Integrations\Models\IntegrationWebhook
 *
 * @extends Builder<TModel>
 */
class IntegrationWebhookBuilder extends Builder
{
    public function pending(): static
    {
        $this->where('status', 'pending');

        return $this;
    }

    public function failed(): static
    {
        $this->where('status', 'failed');

        return $this;
    }

    public function forEventType(string $eventType): static
    {
        $this->where('event_type', $eventType);

        return $this;
    }

    public function recent(int $hours = 24): static
    {
        $this->where('created_at', '>=', now()->subHours($hours));

        return $this;
    }

    public function staleProcessing(int $timeoutSeconds): static
    {
        $this->where('status', 'processing')
            ->where('updated_at', '<', now()->subSeconds($timeoutSeconds));

        return $this;
    }
}
