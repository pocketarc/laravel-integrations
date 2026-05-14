<?php

declare(strict_types=1);

namespace Integrations\Sync;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Base class for per-item sync events: an adapter's `TicketSynced`,
 * `IssueSynced`, etc.
 *
 * Adapters do not dispatch these directly. They hand each one to
 * `SyncSession::dispatch()`; the framework wraps it in a `ProcessSyncItem`
 * queue job and invokes its listeners synchronously inside that job. The
 * job's success or failure then reflects the listeners', so the sync
 * cursor only advances past items whose listeners actually completed.
 *
 * Because of that, listeners for these events MUST NOT implement
 * `ShouldQueue`. The wrapper job is already the queued unit, and a queued
 * listener would let the job report success before the listener ran. The
 * `ProcessSyncItem` job throws `SyncListenerMustNotBeQueuedException` if it
 * detects one. A listener that needs to fan out async work should dispatch
 * its own job from inside its (synchronous) body.
 *
 * `SerializesModels` is included because the event is serialized as a
 * constructor argument of the `ProcessSyncItem` job: Eloquent models on the
 * event survive the queue round-trip by primary-key reference.
 */
abstract class SyncItemEvent
{
    use Dispatchable;
    use SerializesModels;
}
