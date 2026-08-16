<?php

declare(strict_types=1);

namespace Integrations\Tests\Fixtures;

use Integrations\Models\Integration;
use Integrations\Sync\SyncItemEvent;

/** A second event class for one record, for the dedup key's class half. */
class OtherTestSyncItemEvent extends SyncItemEvent
{
    public function __construct(
        public readonly Integration $integration,
        public readonly string $itemId,
    ) {}
}
