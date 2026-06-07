<?php

declare(strict_types=1);

namespace Integrations\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Integrations\Models\Integration;
use Integrations\Models\IntegrationLog;
use Integrations\Sync\SyncAttemptContext;

class OperationFailed
{
    use Dispatchable;

    public function __construct(
        public readonly Integration $integration,
        public readonly IntegrationLog $log,
        /**
         * Retry-attempt context when the failure was logged inside a sync
         * item run; null otherwise. Lets a consumer down-rank mid-retry noise
         * (see SyncAttemptContext::isLikelyFinalAttempt()), while SyncItemFailed
         * remains the authoritative once-only terminal signal.
         */
        public readonly ?SyncAttemptContext $attempt = null,
    ) {}
}
