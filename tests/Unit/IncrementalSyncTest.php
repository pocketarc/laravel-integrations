<?php

declare(strict_types=1);

namespace Integrations\Tests\Unit;

use Integrations\IntegrationManager;
use Integrations\Jobs\SyncIntegration;
use Integrations\Models\Integration;
use Integrations\Sync\SyncResult;
use Integrations\Tests\Fixtures\IncrementalProvider;
use Integrations\Tests\Fixtures\TestProvider;
use Integrations\Tests\TestCase;

class IncrementalSyncTest extends TestCase
{
    public function test_sync_result_carries_cursor(): void
    {
        $result = new SyncResult(5, 0, now(), cursor: ['page' => 3]);

        $this->assertSame(['page' => 3], $result->cursor);
    }

    public function test_sync_result_cursor_defaults_to_null(): void
    {
        $result = new SyncResult(1, 0, now());

        $this->assertNull($result->cursor);
    }

    public function test_incremental_sync_uses_cursor(): void
    {
        app(IntegrationManager::class)->register('incremental', IncrementalProvider::class);

        $integration = Integration::create([
            'provider' => 'incremental',
            'name' => 'Incremental',
            'sync_interval_minutes' => 15,
            'sync_cursor' => ['page' => 1],
        ]);

        $job = new SyncIntegration($integration->id);
        $job->handle();

        $this->assertSame(['page' => 1], IncrementalProvider::$receivedCursor);

        $integration->refresh();
        $this->assertSame(['page' => 2], $integration->sync_cursor);
    }

    public function test_update_sync_cursor(): void
    {
        app(IntegrationManager::class)->register('test', TestProvider::class);

        $integration = Integration::create(['provider' => 'test', 'name' => 'Test']);
        $integration->updateSyncCursor(['token' => 'abc123']);

        $integration->refresh();
        $this->assertSame(['token' => 'abc123'], $integration->sync_cursor);
    }
}
