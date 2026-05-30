<?php

declare(strict_types=1);

namespace Integrations\Enums;

/**
 * An operator's runtime override of an integration's circuit breaker, stored
 * on the integration so it survives a cache flush. The absence of a value
 * (a null column) means "auto" — the normal state machine runs.
 */
enum CircuitOverride: string
{
    /** Hold the breaker open: every request short-circuits, regardless of health. */
    case ForcedOpen = 'forced_open';

    /** Hold the breaker closed: requests always pass and failures never trip it. */
    case ForcedClosed = 'forced_closed';

    /** Bypass the breaker entirely: no short-circuiting, no accounting. */
    case Disabled = 'disabled';
}
