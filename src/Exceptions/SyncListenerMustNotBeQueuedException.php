<?php

declare(strict_types=1);

namespace Integrations\Exceptions;

use RuntimeException;

/**
 * Thrown by `ProcessSyncItem` when a listener registered for a
 * `SyncItemEvent` implements `ShouldQueue`.
 *
 * Sync events are dispatched inside the framework's `ProcessSyncItem` queue
 * job, which retries the listener and tracks its completion so the sync
 * cursor only advances once the work is done. A queued listener would let
 * that job report success the instant the listener job is enqueued, before
 * any work runs, which is exactly the silent-data-loss gap this design
 * closes. So we fail loudly instead.
 */
class SyncListenerMustNotBeQueuedException extends RuntimeException
{
    public static function for(string $listenerClass, string $eventClass): self
    {
        return new self(sprintf(
            'Listener [%s] for sync event [%s] must not implement ShouldQueue. '
            .'Sync events run inside the framework\'s ProcessSyncItem queue job, which '
            .'already provides queueing, retries, and completion tracking. Remove '
            .'ShouldQueue from the listener; if it needs async follow-up work, dispatch '
            .'a separate job from inside the listener body.',
            $listenerClass,
            $eventClass,
        ));
    }
}
