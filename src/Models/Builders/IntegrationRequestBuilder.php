<?php

declare(strict_types=1);

namespace Integrations\Models\Builders;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Integrations\Enums\FailureClass;

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

    public function withFailureClass(FailureClass $class): static
    {
        $this->where('failure_class', $class->value);

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

    public function since(CarbonInterface $since): static
    {
        $this->where('created_at', '>=', $since);

        return $this;
    }
}
