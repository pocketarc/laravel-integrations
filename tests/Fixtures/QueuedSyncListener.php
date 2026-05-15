<?php

declare(strict_types=1);

namespace Integrations\Tests\Fixtures;

use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * A listener that (wrongly) implements ShouldQueue. ProcessSyncItem must
 * reject this; the wrapper job is already the queued unit.
 */
class QueuedSyncListener implements ShouldQueue
{
    public function handle(TestSyncItemEvent $event): void
    {
        // Never reached: ProcessSyncItem fails before invoking it.
    }
}
