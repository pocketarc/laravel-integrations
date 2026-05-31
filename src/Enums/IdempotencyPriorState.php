<?php

declare(strict_types=1);

namespace Integrations\Enums;

use Integrations\Exceptions\IdempotencyConflict;

/**
 * Outcome of the prior-attempt lookup that fires when an idempotency-keyed
 * call hits {@see IdempotencyConflict}. Carried on
 * the exception as `$e->priorState` so the catch block can render a
 * branch-specific operator message — recovery means very different things
 * for "the row was reserved but no API request followed" versus "the
 * persisted response_data is unparseable", and the package shouldn't
 * collapse them into a single null sentinel.
 *
 * See `docs/core-concepts/idempotency.md` § "Recovering on conflict".
 */
enum IdempotencyPriorState: string
{
    /**
     * No `integration_requests` row exists for the conflicting key. The
     * idempotency-key row was inserted by something that never completed
     * a request — a test fixture, an operator pre-seed, or a race where
     * the original caller crashed between `reserve()` and the API call.
     * Recovery is impossible; the key row may need manual removal.
     */
    case NoRow = 'no_row';

    /**
     * A successful `integration_requests` row exists but its `response_data`
     * is null or the empty string. Typical sources: a 204 No Content reply,
     * or a closure that returned `null` and was normalised that way. Nothing
     * to hydrate; the call's effect happened upstream but produced no
     * recoverable body.
     */
    case EmptyBody = 'empty_body';

    /**
     * `response_data` is present but can't be parsed into a JSON object —
     * either invalid JSON, or valid JSON that decodes to a non-array (a
     * bare string / number / boolean). Signals corruption, a legacy schema
     * the persisted payload no longer matches, or an upstream closure that
     * returned an unexpected shape.
     */
    case Unparseable = 'unparseable';

    /**
     * `response_data` decoded cleanly into an array; `$e->priorResponse`
     * holds it and the caller can hydrate / replay.
     */
    case Recovered = 'recovered';
}
