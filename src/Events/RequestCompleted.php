<?php

declare(strict_types=1);

namespace Integrations\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Integrations\Models\Integration;
use Integrations\Models\IntegrationRequest;

class RequestCompleted
{
    use Dispatchable;

    public function __construct(
        public readonly Integration $integration,
        public readonly IntegrationRequest $request,
    ) {}
}
