<?php

declare(strict_types=1);

namespace Integrations\Tests\Unit;

use Illuminate\Support\Facades\Event;
use Integrations\Events\RequestCompleted;
use Integrations\IntegrationManager;
use Integrations\Models\Integration;
use Integrations\Tests\Fixtures\TestProvider;
use Integrations\Tests\TestCase;

class RequestLoggingConfigTest extends TestCase
{
    private Integration $integration;

    protected function setUp(): void
    {
        parent::setUp();

        $manager = app(IntegrationManager::class);
        $manager->register('test', TestProvider::class);

        $this->integration = Integration::create(['provider' => 'test', 'name' => 'Test']);
        $this->integration->refresh();
    }

    public function test_disabling_logging_skips_event_dispatch(): void
    {
        Event::fake();
        config(['integrations.request_logging.enabled' => false]);

        $result = $this->integration->request(
            endpoint: '/api/data',
            method: 'GET',
            callback: fn () => ['ok' => true],
        );

        $this->assertSame(['ok' => true], $result);
        $this->assertDatabaseCount('integration_requests', 1);
        Event::assertNotDispatched(RequestCompleted::class);
    }

    public function test_health_tracking_works_with_logging_disabled(): void
    {
        config(['integrations.request_logging.enabled' => false]);

        $this->integration->update(['consecutive_failures' => 3]);

        $this->integration->request(
            endpoint: '/api/data',
            method: 'GET',
            callback: fn () => ['ok' => true],
        );

        $this->integration->refresh();
        $this->assertSame(0, $this->integration->consecutive_failures);
        $this->assertDatabaseCount('integration_requests', 1);
    }
}
