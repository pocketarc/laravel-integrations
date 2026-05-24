<?php

declare(strict_types=1);

namespace Integrations\Exceptions;

use Integrations\Enums\IdempotencyPriorState;
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
 * `$this->priorState` is one of the {@see IdempotencyPriorState} cases
 * (`NoRow`, `EmptyBody`, `Unparseable`, or `Recovered`) telling the
 * catch block what shape the prior attempt left things in, so an
 * operator-facing message can match the actual condition instead of
 * collapsing four causes into one. `$this->priorRowId` carries the
 * `integration_requests.id` of the prior row when one exists (every
 * state except `NoRow`), useful for cross-referencing logs.
 *
 * `$this->priorResponse` carries the decoded JSON response body when
 * `priorState === Recovered`; null in every other state. Hydrating the
 * array into a domain DTO is the caller's choice; the exception stays
 * provider-agnostic. See {@see Integration::getIdempotencyRecovery()}
 * for the underlying lookup and `docs/core-concepts/idempotency.md`
 * § "Recovering on conflict" for usage patterns.
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
        public readonly IdempotencyPriorState $priorState = IdempotencyPriorState::NoRow,
        public readonly ?int $priorRowId = null,
    ) {
        parent::__construct(
            "Idempotency key already in use for integration {$integrationId} and key '{$key}'.",
            0,
            $previous,
        );
    }
}
