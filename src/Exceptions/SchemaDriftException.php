<?php

declare(strict_types=1);

namespace Integrations\Exceptions;

use Integrations\Models\Integration;
use RuntimeException;
use Spatie\LaravelData\Data;
use Throwable;

/**
 * Thrown when a Spatie Data class fails to hydrate a response, either
 * fresh from the provider (`source: 'live'`) or after being read back
 * from the request cache (`source: 'cache'`).
 *
 * On the live path, this signals the upstream API has changed shape (or
 * the local Data class is wrong) — either way the caller can't get the
 * typed response they asked for, so we throw rather than emit an event
 * that's easy to ignore.
 *
 * On the cache path, this signals a poisoned cache entry — typically
 * because the Data class was changed in a way that doesn't match
 * previously-stored payloads. Throwing forces the user to clear the
 * cache or fix the class, both of which are bug-class problems that
 * shouldn't be silently swallowed.
 *
 * Callers who genuinely want to degrade gracefully can catch this and
 * fall back. The default behavior is a loud failure.
 */
class SchemaDriftException extends RuntimeException
{
    /**
     * @param  Integration  $integration  the integration whose response failed to hydrate
     * @param  class-string<Data>  $responseClass  the Data class the executor tried to hydrate into
     * @param  mixed  $parsedData  the raw payload that failed to hydrate (for debugging)
     * @param  'live'|'cache'  $source  whether the failure came from a fresh response or a cached one
     */
    public function __construct(
        public readonly Integration $integration,
        public readonly string $responseClass,
        public readonly mixed $parsedData,
        public readonly string $source,
        ?Throwable $previous = null,
    ) {
        $message = sprintf(
            'Failed to hydrate %s from %s response for integration %s: %s',
            $responseClass,
            $source,
            $integration->id,
            $previous?->getMessage() ?? 'unknown error',
        );

        parent::__construct($message, 0, $previous);
    }
}
