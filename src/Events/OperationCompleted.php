<?php

declare(strict_types=1);

namespace Integrations\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Integrations\Models\Integration;
use Integrations\Models\IntegrationLog;

class OperationCompleted
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly Integration $integration,
        public readonly IntegrationLog $log,
    ) {}
}
