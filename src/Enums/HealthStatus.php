<?php

declare(strict_types=1);

namespace Integrations\Enums;

enum HealthStatus: string
{
    case Healthy = 'healthy';
    case Degraded = 'degraded';
    case Failing = 'failing';
    case Disabled = 'disabled';

    /**
     * Ordinal severity, worst-is-highest. The single ordering used to track an
     * incident's peak severity and to compare states.
     */
    public function severity(): int
    {
        return match ($this) {
            self::Healthy => 0,
            self::Degraded => 1,
            self::Failing => 2,
            self::Disabled => 3,
        };
    }

    public function isAtLeast(self $other): bool
    {
        return $this->severity() >= $other->severity();
    }
}
