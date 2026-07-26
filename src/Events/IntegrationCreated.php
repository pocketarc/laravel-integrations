<?php

declare(strict_types=1);

namespace Integrations\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Integrations\Models\Integration;

/**
 * Held until the surrounding transaction commits, because Eloquent's `created`
 * hook fires on insert. `upsertByExternalId()` rolls back that insert when it
 * loses the mapping claim, and without the hold a listener would already have
 * run against a row that never persisted.
 */
class IntegrationCreated implements ShouldDispatchAfterCommit
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly Integration $integration,
    ) {}
}
