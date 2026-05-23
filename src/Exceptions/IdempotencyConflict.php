<?php

declare(strict_types=1);

namespace Integrations\Exceptions;

use Integrations\Models\Integration;
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
 *
 * `$this->priorResponse` carries the decoded JSON response body of
 * the previous successful keyed call (looked up via
 * {@see Integration::getIdempotencyResponse()})
 * so the catch block can recover the original result without an
 * extra DB query. It is `null` when no recoverable prior is on file
 * (the row was inserted directly, the original call hadn't landed
 * yet, the response was logged as failed, or the persisted JSON is
 * unparseable). Hydrating the array into a domain DTO is the
 * caller's choice — the exception stays provider-agnostic.
 */
class IdempotencyConflict extends RuntimeException
{
    /**
     * @param  array<array-key, mixed>|null  $priorResponse
     */
    public function __construct(
        public readonly int $integrationId,
        public readonly string $key,
        ?\Throwable $previous = null,
        public readonly ?array $priorResponse = null,
    ) {
        parent::__construct(
            "Idempotency key already in use for integration {$integrationId} and key '{$key}'.",
            0,
            $previous,
        );
    }
}
