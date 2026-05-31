<?php

declare(strict_types=1);

namespace Integrations\Enums;

use Integrations\Contracts\ClassifiesFailures;
use Integrations\Contracts\CustomizesRetry;
use Integrations\Support\FailureClassifier;

/**
 * How a failed request reflects on the upstream's health. Computed once per
 * failure by {@see FailureClassifier} and fed to both
 * the circuit breaker and the integration's health tracking, so the two can
 * never disagree about what counts as "the dependency is broken".
 */
enum FailureClass: string
{
    /** 5xx (except 501), connection refused, timeout — the dependency is down. */
    case Upstream = 'upstream';

    /** 429 / provider rate-limit — retryable, but the upstream is healthy and just pacing us. */
    case Throttle = 'throttle';

    /** 4xx other than 429 (and 501) — the caller's input is wrong; retrying won't help. */
    case Client = 'client';

    /** No positive evidence either way (null status, unrecognised SDK exception). */
    case Unknown = 'unknown';

    /**
     * The single source of truth for "does this hurt resilience". Only an
     * upstream fault should open the breaker or degrade health; throttles and
     * client errors are someone else's problem, and an unknown failure is not
     * enough evidence to penalise the integration.
     */
    public function countsAsFailure(): bool
    {
        return $this === self::Upstream;
    }

    /**
     * Whether a failure of this class is worth retrying. Lets classification
     * drive the retry decision for providers that implement
     * {@see ClassifiesFailures} but not
     * {@see CustomizesRetry}.
     */
    public function isRetryable(): bool
    {
        return $this === self::Upstream || $this === self::Throttle;
    }

    /**
     * Canonical HTTP-status to class mapping. Shared by the core classifier
     * and every official adapter so the table lives in exactly one place.
     */
    public static function fromStatus(int $status): self
    {
        return match (true) {
            $status === 429 => self::Throttle,
            $status === 501 => self::Client,
            $status >= 500 => self::Upstream,
            $status >= 400 => self::Client,
            default => self::Unknown,
        };
    }
}
