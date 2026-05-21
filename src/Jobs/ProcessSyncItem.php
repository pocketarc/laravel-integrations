<?php

declare(strict_types=1);

namespace Integrations\Jobs;

use DateTimeInterface;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Str;
use Integrations\Events\SyncItemFailed;
use Integrations\Exceptions\RateLimitExceededException;
use Integrations\Exceptions\SyncListenerMustNotBeQueuedException;
use Integrations\Models\IntegrationSyncItem;
use Integrations\Support\Config;
use Integrations\Sync\SyncItemEvent;
use Throwable;

/**
 * Wraps one per-item sync event in a queued job.
 *
 * Runs the event's listeners synchronously so the job's success or failure
 * reflects the listeners', updates the item's `integration_sync_items` row,
 * and, when this run is a manual retry of a previously-failed item,
 * re-triggers the run's finalisation so the cursor can catch up.
 *
 * Listeners for `SyncItemEvent`s must not implement `ShouldQueue`: this job
 * is already the queued unit. If a queued listener is registered, the job
 * fails immediately with `SyncListenerMustNotBeQueuedException` rather than
 * letting the listener run detached and the run report false success.
 *
 * A `RateLimitExceededException` surfacing from a listener is treated as a
 * transient deferral, not a failure: the job is released back to the queue
 * with the limiter's retry-after delay and the item stays in flight. That
 * relies on the listener not swallowing the exception.
 */
class ProcessSyncItem implements ShouldQueue
{
    use Batchable;
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public readonly int $maxExceptions;

    public function __construct(
        public readonly int $syncItemId,
        public readonly SyncItemEvent $event,
        public readonly int $syncLogId,
    ) {
        $this->maxExceptions = Config::syncItemTries();
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return Config::syncItemBackoff();
    }

    /**
     * Absolute wall-clock deadline for this job. A non-null retryUntil()
     * makes Laravel ignore the attempt-count ceiling, so rate-limit
     * deferrals (release()) can re-queue freely without tripping it, while
     * genuine listener exceptions stay bounded by maxExceptions. Frozen at
     * first dispatch.
     */
    public function retryUntil(): DateTimeInterface
    {
        return now()->addSeconds(Config::syncItemRetryWindow());
    }

    public function handle(): void
    {
        if ($this->batch()?->cancelled() === true) {
            return;
        }

        $item = IntegrationSyncItem::query()->find($this->syncItemId);
        if ($item === null) {
            return;
        }

        $queuedListener = $this->findQueuedListener();
        if ($queuedListener !== null) {
            // A queued listener would let this job report success the instant
            // the listener is enqueued, before any work runs. Fail loudly and
            // immediately (no retries); it's a configuration error.
            $this->fail(SyncListenerMustNotBeQueuedException::for($queuedListener, $this->event::class));

            return;
        }

        $item->update([
            'status' => IntegrationSyncItem::STATUS_PROCESSING,
            'attempts' => $this->attempts(),
        ]);

        // Listeners run synchronously here (we verified none are queued). A
        // throw propagates out, so the queue retries the whole job, which
        // means listeners must be idempotent.
        try {
            event($this->event);
        } catch (RateLimitExceededException $e) {
            // A rate limit is transient, not a failure: park the item and
            // retry once the provider's window reopens. release() re-queues
            // this job as a still-pending member of the batch, keeping the
            // run in flight. It does not count against maxExceptions, so a
            // throttled item is never marked failed just for waiting.
            $this->release($e->retryAfterSeconds);

            return;
        }

        $item->update([
            'status' => IntegrationSyncItem::STATUS_SUCCESS,
            'attempts' => $this->attempts(),
            'completed_at' => now(),
            'error' => null,
        ]);

        $this->finaliseIfRetryCompletedTheRun($item);
    }

    public function failed(Throwable $e): void
    {
        $item = IntegrationSyncItem::query()->find($this->syncItemId);
        if ($item === null) {
            return;
        }

        $item->update([
            'status' => IntegrationSyncItem::STATUS_FAILED,
            'attempts' => $this->attempts(),
            'completed_at' => now(),
            'error' => Str::limit($e->getMessage(), 1000),
        ]);

        $integration = $item->integration;
        if ($integration !== null) {
            SyncItemFailed::dispatch($integration, $item, $e);
        }
    }

    /**
     * In the normal flow the batch's `finally` callback dispatches
     * `FinaliseSyncRun` once every job has run. But when this job is a manual
     * retry of one that previously failed, the batch already finished and
     * `finally` won't fire again, so we re-trigger finalisation ourselves,
     * letting the cursor advance now that the item finally succeeded.
     */
    private function finaliseIfRetryCompletedTheRun(IntegrationSyncItem $item): void
    {
        $batch = $this->batch();

        if ($batch === null || ! $batch->finished()) {
            return;
        }

        FinaliseSyncRun::dispatch($item->integration_id, $this->syncLogId);
    }

    /**
     * The class name of the first registered listener for this event that
     * implements `ShouldQueue`, or null if there are none. Closure listeners
     * can't be introspected and are not checked; that limitation is
     * documented for consumers.
     */
    private function findQueuedListener(): ?string
    {
        $listeners = app('events')->getRawListeners()[$this->event::class] ?? [];
        if (! is_array($listeners)) {
            return null;
        }

        foreach ($listeners as $listener) {
            $class = $this->listenerClassName($listener);
            if ($class !== null && class_exists($class) && is_subclass_of($class, ShouldQueue::class)) {
                return $class;
            }
        }

        return null;
    }

    private function listenerClassName(mixed $listener): ?string
    {
        if (is_string($listener)) {
            $class = Str::before($listener, '@');

            return $class !== '' ? $class : null;
        }

        if (is_array($listener) && array_key_exists(0, $listener)) {
            $first = $listener[0];

            if (is_string($first)) {
                return $first;
            }

            if (is_object($first)) {
                return $first::class;
            }
        }

        return null;
    }
}
