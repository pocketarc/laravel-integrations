<?php

declare(strict_types=1);

namespace Integrations\Contracts;

use Integrations\Models\Integration;

interface HasHealthCheck
{
    /**
     * Perform a lightweight health check on the integration connection.
     */
    public function healthCheck(Integration $integration): bool;
}
