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
        return $this->where('status', 'pending');
    }

    public function failed(): static
    {
        return $this->where('status', 'failed');
    }

    public function forEventType(string $eventType): static
    {
        return $this->where('event_type', $eventType);
    }

    public function recent(int $hours = 24): static
    {
        return $this->where('created_at', '>=', now()->subHours($hours));
    }
}
