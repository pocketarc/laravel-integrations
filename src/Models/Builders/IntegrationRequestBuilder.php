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
        $this->where('response_success', true);

        return $this;
    }

    public function failed(): static
    {
        $this->where('response_success', false);

        return $this;
    }

    public function forEndpoint(string $endpoint): static
    {
        $this->where('endpoint', $endpoint);

        return $this;
    }

    public function recent(int $hours = 24): static
    {
        $this->where('created_at', '>=', now()->subHours($hours));

        return $this;
    }
}
