<?php

declare(strict_types=1);

namespace Integrations\Contracts;

use Integrations\Models\Integration;

interface HasScheduledSync
{
    /**
     * Perform a sync for the given integration.
     */
    public function sync(Integration $integration): void;

    /**
     * Default sync interval in minutes.
     */
    public function defaultSyncInterval(): int;

    /**
     * Maximum API requests per minute for this provider, or null for unlimited.
     */
    public function defaultRateLimit(): ?int;
}
