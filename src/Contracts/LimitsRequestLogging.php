<?php

declare(strict_types=1);

namespace Integrations\Contracts;

/**
 * Opts a provider's noisiest endpoints out of storing their response body.
 *
 * The request row is still written, so health, failure rates, request counts
 * and the stats commands are unaffected: only the body is dropped. Use it
 * where the payload is either enormous or already stored better elsewhere,
 * such as an AI provider whose responses a consumer persists in its own
 * replayable form.
 *
 * A body the package itself needs is never dropped, whatever this returns:
 * a response being cached is the cache's payload, and a response to an
 * idempotent write backs `IdempotencyConflict` recovery.
 */
interface LimitsRequestLogging
{
    /**
     * Endpoint patterns whose response bodies are not worth storing.
     *
     * Matched against the endpoint recorded on the request row, with `*` as
     * a wildcard within a path segment: `issues/*` matches `issues/42`. An
     * optional HTTP-verb prefix narrows a pattern to one method, as in
     * `POST:chat/completions`.
     *
     * @return list<string>
     */
    public function unloggedResponseEndpoints(): array;
}
