<?php

declare(strict_types=1);

namespace Integrations\Support;

use Illuminate\Http\Client\ConnectionException;
use Integrations\Contracts\ClassifiesFailures;
use Integrations\Contracts\IntegrationProvider;
use Integrations\Enums\FailureClass;
use Integrations\Exceptions\CircuitOpenException;
use Throwable;

/**
 * Decides what an exception thrown during a request means for the upstream's
 * health. Consulted once per failure (in the request executor) and shared by
 * the circuit breaker and the integration's health counters.
 *
 * Resolution order, first match wins:
 *   1. CircuitOpenException -> Unknown. The breaker must never count its own
 *      short-circuit as evidence, or it would stay open forever.
 *   2. The provider's own ClassifiesFailures verdict, when it returns one.
 *   3. An extractable HTTP status -> FailureClass::fromStatus().
 *   4. A ConnectionException anywhere in the chain -> Upstream.
 *   5. Otherwise Unknown. We only penalise on positive evidence, so an
 *      unrecognised SDK exception (e.g. a client-side validation error with no
 *      HTTP status) does not trip the breaker or degrade health.
 */
final class FailureClassifier
{
    public static function classify(Throwable $e, ?IntegrationProvider $provider = null): FailureClass
    {
        if ($e instanceof CircuitOpenException) {
            return FailureClass::Unknown;
        }

        if ($provider instanceof ClassifiesFailures) {
            $provided = $provider->classifyFailure($e);
            if ($provided !== null) {
                return $provided;
            }
        }

        $status = ResponseHelper::extractStatusCode($e);
        if ($status !== null) {
            return FailureClass::fromStatus($status);
        }

        for ($current = $e; $current !== null; $current = $current->getPrevious()) {
            if ($current instanceof ConnectionException) {
                return FailureClass::Upstream;
            }
        }

        return FailureClass::Unknown;
    }
}
