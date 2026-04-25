<?php

declare(strict_types=1);

namespace Integrations\Tests\Unit;

use Illuminate\Support\Facades\Event;
use Integrations\Events\RequestCompleted;
use Integrations\Events\RequestFailed;
use Integrations\IntegrationManager;
use Integrations\Models\Integration;
use Integrations\Tests\Fixtures\TestOkResponse;
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

    public function test_successful_request_dispatches_event(): void
    {
        Event::fake();

        $this->integration->request(
            endpoint: '/api/data',
            method: 'GET',
            responseClass: TestOkResponse::class,
            callback: fn () => ['ok' => true],
        );

        $this->assertDatabaseCount('integration_requests', 1);
        Event::assertDispatched(RequestCompleted::class);
    }

    public function test_failed_request_dispatches_event(): void
    {
        Event::fake();

        $this->assertThrows(function (): void {
            $this->integration->request(
                endpoint: '/api/data',
                method: 'GET',
                responseClass: TestOkResponse::class,
                callback: fn () => throw new \RuntimeException('API error'),
            );
        }, \RuntimeException::class);

        $this->assertDatabaseCount('integration_requests', 1);
        Event::assertDispatched(RequestFailed::class);
    }

    public function test_health_tracking_works_alongside_logging(): void
    {
        $this->integration->update(['consecutive_failures' => 3]);

        $this->integration->request(
            endpoint: '/api/data',
            method: 'GET',
            responseClass: TestOkResponse::class,
            callback: fn () => ['ok' => true],
        );

        $this->integration->refresh();
        $this->assertSame(0, $this->integration->consecutive_failures);
        $this->assertDatabaseCount('integration_requests', 1);
    }
}
