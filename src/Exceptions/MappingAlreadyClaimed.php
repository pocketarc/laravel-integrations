<?php

declare(strict_types=1);

namespace Integrations\Exceptions;

use Integrations\Models\Integration;
use RuntimeException;

/**
 * Thrown by {@see Integration::mapExternalId()} when the external ID is already
 * mapped to a different local model.
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
