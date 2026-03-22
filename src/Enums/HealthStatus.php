<?php

declare(strict_types=1);

namespace Integrations\Enums;

enum HealthStatus: string
{
    case Healthy = 'healthy';
    case Degraded = 'degraded';
    case Failing = 'failing';
}
