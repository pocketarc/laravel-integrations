<?php

declare(strict_types=1);

namespace Integrations\Tests\Unit;

use Integrations\IntegrationManager;
use Integrations\Models\Integration;
use Integrations\Tests\Fixtures\TestOkResponse;
use Integrations\Tests\Fixtures\TestProvider;
use Integrations\Tests\TestCase;

class SyncTimelineTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        app(IntegrationManager::class)->register('test', TestProvider::class);
    }

    public function test_requests_tracked_during_sync_context(): void
    {
        $integration = Integration::create(['provider' => 'test', 'name' => 'Test']);
        $integration->refresh();

        $integration->setSyncContext(42);

        $integration->requestAs(
            endpoint: '/api/first',
            method: 'GET',
            responseClass: TestOkResponse::class,
            callback: fn () => ['ok' => true],
        );

        $integration->requestAs(
            endpoint: '/api/second',
            method: 'GET',
            responseClass: TestOkResponse::class,
            callback: fn () => ['ok' => true],
        );

        $requestIds = $integration->clearSyncContext();

        $this->assertCount(2, $requestIds);
        $this->assertIsInt($requestIds[0]);
        $this->assertIsInt($requestIds[1]);
    }

    public function test_requests_not_tracked_outside_sync_context(): void
    {
        $integration = Integration::create(['provider' => 'test', 'name' => 'Test']);
        $integration->refresh();

        $integration->requestAs(
            endpoint: '/api/test',
            method: 'GET',
            responseClass: TestOkResponse::class,
            callback: fn () => ['ok' => true],
        );

        $requestIds = $integration->clearSyncContext();
        $this->assertCount(0, $requestIds);
    }

    public function test_clear_sync_context_resets_state(): void
    {
        $integration = Integration::create(['provider' => 'test', 'name' => 'Test']);
        $integration->refresh();

        $integration->setSyncContext(1);

        $integration->requestAs(
            endpoint: '/api/test',
            method: 'GET',
            responseClass: TestOkResponse::class,
            callback: fn () => ['ok' => true],
        );

        $integration->clearSyncContext();

        // After clearing, new requests should not be tracked
        $integration->requestAs(
            endpoint: '/api/test2',
            method: 'GET',
            responseClass: TestOkResponse::class,
            callback: fn () => ['ok' => true],
        );

        $requestIds = $integration->clearSyncContext();
        $this->assertCount(0, $requestIds);
    }
}
