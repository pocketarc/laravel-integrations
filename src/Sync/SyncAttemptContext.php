<?php

declare(strict_types=1);

namespace Integrations\Sync;

use Integrations\Events\SyncItemFailed;
use Integrations\Jobs\ProcessSyncItem;
use Integrations\Models\Integration;

/**
 * Ambient per-attempt context for a {@see ProcessSyncItem}
 * run. The job sets it around `event($this->event)` and clears it afterwards,
 * so a listener (or `Integration::logOperation()`) can read the retry state
 * via {@see Integration::currentSyncAttempt()} without
 * any change to listener signatures. This mirrors the RequestContext /
 * Integration::currentContext() escape hatch.
 *
 * It is null outside an in-flight sync item — e.g. an operation logged from a
 * webhook or a manual call — so always null-check before reading.
 */
final readonly class SyncAttemptContext
{
    public function __construct(
        /** Current attempt number, 1-indexed (from the job's attempts()). */
        public int $attempt,
        /** Configured retry ceiling (ProcessSyncItem::$maxExceptions). */
        public int $maxAttempts,
        public int $syncItemId,
        public int $integrationId,
        public ?int $syncLogId,
        public ?string $externalId,
    ) {}

    /**
     * Best-effort prediction that this attempt is the last the queue will run.
     * NOT a guarantee: a non-null retryUntil() can cut retries short when the
     * wall-clock window expires before the attempt ceiling, and even on the
     * final attempt the item may still succeed. Treat `true` as "if this
     * attempt throws, it is probably terminal". The authoritative, fire-once
     * terminal signal is {@see SyncItemFailed}, dispatched
     * from ProcessSyncItem::failed() on real exhaustion.
     */
    public function isLikelyFinalAttempt(): bool
    {
        return $this->attempt >= $this->maxAttempts;
    }
}
