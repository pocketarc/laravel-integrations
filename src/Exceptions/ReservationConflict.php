<?php

declare(strict_types=1);

namespace Integrations\Exceptions;

use RuntimeException;

/**
 * Thrown by Integration::withReservation() when a row for
 * (integration_id, key) already exists. Means another worker (or a
 * previous attempt of the same job) already ran the reserved callable
 * to completion. The current caller should treat the work as already
 * done and skip it; if the callable's side effect is observable
 * elsewhere (a row, a remote ticket, etc.) the caller can re-fetch it.
 *
 * The conflicting key is exposed on `$this->key` for callers that
 * need to inspect it. It is intentionally omitted from the exception
 * message so unhandled instances logged by Laravel's handler don't
 * leak application-supplied identifiers into shared log infrastructure.
 */
class ReservationConflict extends RuntimeException
{
    public function __construct(
        public readonly int $integrationId,
        public readonly string $key,
        ?\Throwable $previous = null,
    ) {
        parent::__construct(
            "Reservation already exists for integration {$integrationId}.",
            0,
            $previous,
        );
    }
}
