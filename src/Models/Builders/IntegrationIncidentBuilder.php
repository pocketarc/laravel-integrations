<?php

declare(strict_types=1);

namespace Integrations\Models\Builders;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Integrations\Models\IntegrationIncident;

/**
 * @template TModel of \Integrations\Models\IntegrationIncident
 *
 * @extends Builder<TModel>
 */
class IntegrationIncidentBuilder extends Builder
{
    public function open(): static
    {
        $this->where('status', IntegrationIncident::STATUS_OPEN);

        return $this;
    }

    public function closed(): static
    {
        $this->where('status', IntegrationIncident::STATUS_CLOSED);

        return $this;
    }

    public function forIntegration(int $integrationId): static
    {
        $this->where('integration_id', $integrationId);

        return $this;
    }

    public function since(CarbonInterface $since): static
    {
        $this->where('opened_at', '>=', $since);

        return $this;
    }
}
