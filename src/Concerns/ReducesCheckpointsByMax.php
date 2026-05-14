<?php

declare(strict_types=1);

namespace Integrations\Concerns;

/**
 * Default checkpoint-reduction strategy for incremental-sync providers: the
 * new cursor is the maximum of the completed checkpoint values.
 *
 * Correct for ISO-8601 timestamp cursors and lexicographically-ordered ids,
 * which is what the official adapters use. Providers with non-comparable
 * cursors (page numbers, opaque tokens) should skip this trait and
 * implement `reduceCheckpoints()` directly.
 */
trait ReducesCheckpointsByMax
{
    /**
     * @param  list<mixed>  $checkpoints  Completed checkpoint values, in dispatch order.
     */
    public function reduceCheckpoints(array $checkpoints): mixed
    {
        if ($checkpoints === []) {
            return null;
        }

        return max($checkpoints);
    }
}
