<?php

declare(strict_types=1);

namespace Integrations;

use Integrations\Enums\IdempotencyPriorState;
use Integrations\Exceptions\IdempotencyConflict;
use Integrations\Models\Integration;

/**
 * Result of {@see Integration::getIdempotencyRecovery()}.
 * Bundles the three pieces of information a caller needs to render a
 * branch-specific recovery message: whether a prior successful request
 * exists, which `integration_requests` row it points at, and the decoded
 * response body when one is recoverable.
 *
 * Also populated onto {@see IdempotencyConflict}
 * eagerly by {@see IdempotencyKeyManager} so the catch block doesn't have
 * to call `getIdempotencyRecovery()` itself.
 */
final class IdempotencyRecovery
{
    /**
     * @param  array<array-key, mixed>|null  $priorResponse  Decoded response array when `priorState` is `Recovered`; null otherwise.
     */
    public function __construct(
        public readonly IdempotencyPriorState $priorState,
        public readonly ?int $priorRowId = null,
        public readonly ?array $priorResponse = null,
    ) {}
}
