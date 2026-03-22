<?php

declare(strict_types=1);

namespace Integrations\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Integrations\Enums\HealthStatus;
use Integrations\Models\Integration;

class IntegrationHealthChanged
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly Integration $integration,
        public readonly HealthStatus $previousStatus,
        public readonly HealthStatus $newStatus,
    ) {}
}
