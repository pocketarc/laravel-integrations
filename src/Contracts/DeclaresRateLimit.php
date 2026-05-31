<?php

declare(strict_types=1);

namespace Integrations\Contracts;

use Integrations\RateLimit;

/**
 * Lets a provider ship an in-code API rate budget. The budget is a
 * transport property of the upstream, independent of whether the provider
 * also runs scheduled syncs, so a request-only provider can declare one
 * without implementing {@see HasScheduledSync}. `HasScheduledSync` extends
 * this contract, so every sync provider already satisfies it.
 *
 * The framework reads the value through `Integration::effectiveRateLimit()`,
 * where a runtime row-level override takes precedence over it.
 */
interface DeclaresRateLimit
{
    /**
     * The API rate budget for this provider, or null for unlimited.
     *
     * The window is part of the limit. Build one with the named
     * constructors: `RateLimit::perHour(5000)` for a fixed hourly budget,
     * or `RateLimit::perMinute(700)->sliding()` for an upstream that
     * enforces a rolling per-minute limit.
     */
    public function defaultRateLimit(): ?RateLimit;
}
