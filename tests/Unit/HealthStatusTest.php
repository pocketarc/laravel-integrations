<?php

declare(strict_types=1);

namespace Integrations\Tests\Unit;

use Integrations\Enums\HealthStatus;
use Integrations\Tests\TestCase;

class HealthStatusTest extends TestCase
{
    public function test_severity_orders_worst_highest(): void
    {
        $this->assertSame(0, HealthStatus::Healthy->severity());
        $this->assertSame(1, HealthStatus::Degraded->severity());
        $this->assertSame(2, HealthStatus::Failing->severity());
        $this->assertSame(3, HealthStatus::Disabled->severity());
    }

    public function test_is_at_least(): void
    {
        $this->assertTrue(HealthStatus::Failing->isAtLeast(HealthStatus::Degraded));
        $this->assertTrue(HealthStatus::Degraded->isAtLeast(HealthStatus::Degraded));
        $this->assertFalse(HealthStatus::Degraded->isAtLeast(HealthStatus::Failing));
        $this->assertTrue(HealthStatus::Disabled->isAtLeast(HealthStatus::Healthy));
    }
}
