<?php

declare(strict_types=1);

namespace Integrations\Tests\Fixtures;

use Integrations\Models\Integration;
use Integrations\Sync\SyncItemEvent;

class TestSyncItemEvent extends SyncItemEvent
{
    public function __construct(
        public readonly Integration $integration,
        public readonly string $itemId,
    ) {}
}
