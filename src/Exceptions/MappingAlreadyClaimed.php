<?php

declare(strict_types=1);

namespace Integrations\Exceptions;

use Integrations\Models\Integration;
use RuntimeException;

/**
 * Thrown by {@see Integration::mapExternalId()} when the external ID is already
 * mapped to a different local model.
 *
 * Until 6.0 that call silently re-pointed the mapping, which made a lost race
 * unobservable: two workers upserting the same external ID would each insert a
 * local row, the second would take the mapping, and the first row was left
 * behind with no mapping at all. It still looked complete to every query, but
 * `findExternalId()` returned null for it forever, so nothing could address it
 * upstream again.
 *
 * `$this->claimedBy` is the `internal_id` currently holding the mapping and
 * `$this->requestedBy` is the one that was refused, both as strings because
 * `integration_mappings.internal_id` is polymorphic across key types.
 *
 * Catch this to converge on the winner (re-resolve and carry on, which is what
 * {@see Integration::upsertByExternalId()} does). Call
 * {@see Integration::remapExternalId()} instead when moving the mapping is the
 * actual intent.
 */
class MappingAlreadyClaimed extends RuntimeException
{
    public function __construct(
        public readonly int $integrationId,
        public readonly string $externalId,
        public readonly string $internalType,
        public readonly string $claimedBy,
        public readonly string $requestedBy,
        ?\Throwable $previous = null,
    ) {
        parent::__construct(
            "External ID '{$externalId}' on integration {$integrationId} is already mapped to "
            ."{$internalType} #{$claimedBy}; refusing to re-point it at #{$requestedBy}. "
            .'Use remapExternalId() if moving the mapping is intended.',
            0,
            $previous,
        );
    }
}
