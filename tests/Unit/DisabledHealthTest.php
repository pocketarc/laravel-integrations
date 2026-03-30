<?php

declare(strict_types=1);

namespace Integrations\Tests\Unit;

use Illuminate\Support\Facades\Event;
use Integrations\Enums\HealthStatus;
use Integrations\Events\IntegrationDisabled;
use Integrations\Events\IntegrationHealthChanged;
use Integrations\IntegrationManager;
use Integrations\Models\Integration;
use Integrations\Tests\Fixtures\TestProvider;
use Integrations\Tests\TestCase;

class DisabledHealthTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        app(IntegrationManager::class)->register('test', TestProvider::class);
    }

    public function test_integration_disabled_after_threshold(): void
    {
        config(['integrations.health.disabled_after' => 3]);

        $integration = Integration::create(['provider' => 'test', 'name' => 'Test']);
        $integration->refresh();

        for ($i = 0; $i < 3; $i++) {
            $integration->recordFailure();
        }

        $integration->refresh();

        $this->assertSame(HealthStatus::Disabled, $integration->health_status);
        $this->assertFalse($integration->is_active);
    }

    public function test_disabled_event_dispatched(): void
    {
        Event::fake([IntegrationDisabled::class]);

        config(['integrations.health.disabled_after' => 1]);

        $integration = Integration::create(['provider' => 'test', 'name' => 'Test']);
        $integration->refresh();
        $integration->recordFailure();

        Event::assertDispatched(IntegrationDisabled::class);
    }

    public function test_health_changed_event_dispatched_on_disable(): void
    {
        Event::fake([IntegrationHealthChanged::class, IntegrationDisabled::class]);

        config(['integrations.health.disabled_after' => 1]);

        $integration = Integration::create(['provider' => 'test', 'name' => 'Test']);
        $integration->refresh();
        $integration->recordFailure();

        Event::assertDispatched(IntegrationHealthChanged::class, function (IntegrationHealthChanged $event): bool {
            return $event->newStatus === HealthStatus::Disabled;
        });
    }

    public function test_record_success_does_not_re_enable_disabled_integration(): void
    {
        config(['integrations.health.disabled_after' => 1]);

        $integration = Integration::create(['provider' => 'test', 'name' => 'Test']);
        $integration->refresh();
        $integration->recordFailure();

        $integration->refresh();
        $this->assertSame(HealthStatus::Disabled, $integration->health_status);

        $integration->recordSuccess();
        $integration->refresh();

        $this->assertSame(HealthStatus::Disabled, $integration->health_status);
        $this->assertFalse($integration->is_active);
    }

    public function test_disabled_integration_excluded_from_due_for_sync(): void
    {
        config(['integrations.health.disabled_after' => 1]);

        $integration = Integration::create([
            'provider' => 'test',
            'name' => 'Test',
            'sync_interval_minutes' => 5,
        ]);
        $integration->refresh();
        $integration->recordFailure();

        $this->assertSame(0, Integration::query()->dueForSync()->count());
    }

    public function test_feature_off_when_disabled_after_is_null(): void
    {
        config(['integrations.health.disabled_after' => null]);

        $integration = Integration::create(['provider' => 'test', 'name' => 'Test']);
        $integration->refresh();

        for ($i = 0; $i < 100; $i++) {
            $integration->recordFailure();
        }

        $integration->refresh();

        $this->assertSame(HealthStatus::Failing, $integration->health_status);
        $this->assertTrue($integration->is_active);
    }
}
