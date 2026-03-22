<?php

declare(strict_types=1);

namespace Integrations\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Integrations\Models\Integration;
use Integrations\Models\IntegrationLog;

class OperationFailed
{
    use Dispatchable;

    public function __construct(
        public readonly Integration $integration,
        public readonly IntegrationLog $log,
    ) {}
}
