<?php

declare(strict_types=1);

namespace Integrations\Exceptions;

use RuntimeException;

/**
 * Thrown by the request builder when a row for (integration_id, key)
 * already exists in `integration_idempotency_keys`. Means another
 * worker (or a previous attempt of the same job) already ran the
 * keyed work to completion. The current caller should treat the work
 * as already done and skip it; if the work's side effect is
 * observable elsewhere (a local row, a remote ticket, etc.) the
 * caller can re-fetch it.
 *
 * The conflicting key is also exposed on `$this->key` for callers
 * that prefer typed access over parsing the exception message.
 */
class IdempotencyConflict extends RuntimeException
{
    public function __construct(
        public readonly int $integrationId,
        public readonly string $key,
        ?\Throwable $previous = null,
    ) {
        parent::__construct(
            "Idempotency key already in use for integration {$integrationId} and key '{$key}'.",
            0,
            $previous,
        );
    }
}
