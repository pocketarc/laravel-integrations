<?php

declare(strict_types=1);

namespace Integrations\Contracts;

use Integrations\Models\Integration;
use Integrations\Sync\SyncResult;

interface HasIncrementalSync extends HasScheduledSync
{
    /**
     * Perform an incremental sync using the cursor from the previous sync.
     *
     * @param  mixed  $cursor  The cursor from the previous sync, or null for the first sync.
     */
    public function syncIncremental(Integration $integration, mixed $cursor): SyncResult;
}
