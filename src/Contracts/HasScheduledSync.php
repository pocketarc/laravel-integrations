<?php

declare(strict_types=1);

namespace Integrations\Contracts;

use Integrations\Models\Integration;
use Integrations\RateLimit;
use Integrations\Sync\SyncSession;

interface HasScheduledSync
{
    /**
     * Enumerate the items to sync, handing each one to
     * `$session->dispatch()`. The framework wraps each item in a queued
     * `ProcessSyncItem` job, batches them, and advances the integration's
     * `sync_cursor` only once every job has succeeded.
     *
     * Implementations must not advance the cursor or dispatch the per-item
     * events themselves; `$session->dispatch()` is the only path.
     */
    public function sync(Integration $integration, SyncSession $session): void;

    /**
     * Default sync interval in minutes.
     */
    public function defaultSyncInterval(): int;

    /**
     * The API rate budget for this provider, or null for unlimited.
     *
     * The window is part of the limit. Build one with the named
     * constructors: `RateLimit::perHour(5000)` for a fixed hourly budget,
     * or `RateLimit::perMinute(700)->sliding()` for an upstream that
     * enforces a rolling per-minute limit.
     */
    public function defaultRateLimit(): ?RateLimit;

    /**
     * Reduce the checkpoint values of a sync run's completed items into the
     * next `sync_cursor` value for the integration. Called by the framework
     * once a run's batch finishes.
     *
     * The `ReducesCheckpointsByMax` trait provides the common implementation
     * (max of comparable values, correct for ISO-8601 / lexicographic
     * cursors). Providers with non-comparable cursors implement this
     * directly; providers with no cursor (full re-syncs) may return null.
     *
     * @param  list<mixed>  $checkpoints  Completed checkpoint values, in dispatch order.
     */
    public function reduceCheckpoints(array $checkpoints): mixed;
}
