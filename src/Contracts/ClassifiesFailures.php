<?php

declare(strict_types=1);

namespace Integrations\Contracts;

use Integrations\Enums\FailureClass;
use Integrations\Support\FailureClassifier;
use Throwable;

/**
 * Lets a provider translate its SDK's exceptions into the package's failure
 * vocabulary. Without this, the core classifier can only read HTTP-shaped
 * exceptions (Laravel/Guzzle/Symfony) plus a best-effort duck-type of common
 * SDK accessors; implement this when the SDK signals failures in a way the
 * defaults can't see, or to be precise about throttles vs upstream faults.
 */
interface ClassifiesFailures
{
    /**
     * Classify an exception thrown during a request callback. Return null to
     * defer to the default {@see FailureClassifier} logic.
     */
    public function classifyFailure(Throwable $e): ?FailureClass;
}
