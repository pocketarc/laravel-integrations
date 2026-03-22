<?php

declare(strict_types=1);

namespace Integrations\Models\Builders;

use Illuminate\Database\Eloquent\Builder;

/**
 * @template TModel of \Integrations\Models\IntegrationRequest
 *
 * @extends Builder<TModel>
 */
class IntegrationRequestBuilder extends Builder
{
    public function successful(): static
    {
        return $this->where('response_success', true);
    }

    public function failed(): static
    {
        return $this->where('response_success', false);
    }

    public function forEndpoint(string $endpoint): static
    {
        return $this->where('endpoint', $endpoint);
    }

    public function recent(int $hours = 24): static
    {
        return $this->where('created_at', '>=', now()->subHours($hours));
    }
}
