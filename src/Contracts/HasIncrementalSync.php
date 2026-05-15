<?php

declare(strict_types=1);

namespace Integrations\Contracts;

use Integrations\Models\Integration;
use Integrations\Sync\SyncSession;

interface HasIncrementalSync extends HasScheduledSync
{
    /**
     * Like `sync()`, but for providers that can fetch only the items
     * changed since the previous run. Read the previous cursor via
     * `$session->cursor()` and use it to scope the upstream request; hand
     * each item to `$session->dispatch()` with the checkpoint value it
     * represents.
     */
    public function syncIncremental(Integration $integration, SyncSession $session): void;
}
