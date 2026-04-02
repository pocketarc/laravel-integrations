<?php

declare(strict_types=1);

namespace Integrations\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Integrations\Models\Integration;

class IntegrationDisabled
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly Integration $integration,
    ) {}
}
