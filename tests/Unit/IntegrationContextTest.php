<?php

declare(strict_types=1);

namespace Integrations\Tests\Unit;

use Illuminate\Support\Facades\Log;
use Integrations\IntegrationManager;
use Integrations\Models\Integration;
use Integrations\Support\IntegrationContext;
use Integrations\Tests\Fixtures\TestProvider;
use Integrations\Tests\TestCase;

class IntegrationContextTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        app(IntegrationManager::class)->register('test', TestProvider::class);
    }

    protected function tearDown(): void
    {
        IntegrationContext::clear();
        Log::flushSharedContext();

        parent::tearDown();
    }

    public function test_push_adds_context_to_log(): void
    {
        $integration = Integration::create(['provider' => 'test', 'name' => 'My Test']);

        IntegrationContext::push($integration, 'sync');

        $context = Log::sharedContext();

        $this->assertSame($integration->id, $context['integration_id']);
        $this->assertSame('test', $context['integration_provider']);
        $this->assertSame('My Test', $context['integration_name']);
        $this->assertSame('sync', $context['integration_operation']);
    }

    public function test_push_without_operation(): void
    {
        $integration = Integration::create(['provider' => 'test', 'name' => 'Test']);

        IntegrationContext::push($integration);

        $context = Log::sharedContext();

        $this->assertArrayHasKey('integration_id', $context);
        $this->assertArrayHasKey('integration_operation', $context);
        $this->assertNull($context['integration_operation']);
    }

    public function test_clear_removes_integration_context(): void
    {
        $integration = Integration::create(['provider' => 'test', 'name' => 'Test']);

        IntegrationContext::push($integration, 'webhook');
        IntegrationContext::clear();

        $context = Log::sharedContext();

        $this->assertArrayNotHasKey('integration_id', $context);
        $this->assertArrayNotHasKey('integration_provider', $context);
        $this->assertArrayNotHasKey('integration_name', $context);
        $this->assertArrayNotHasKey('integration_operation', $context);
    }

    public function test_clear_preserves_non_integration_context(): void
    {
        Log::shareContext(['tenant_id' => 42]);

        $integration = Integration::create(['provider' => 'test', 'name' => 'Test']);
        IntegrationContext::push($integration, 'sync');
        IntegrationContext::clear();

        $context = Log::sharedContext();

        $this->assertSame(42, $context['tenant_id']);
        $this->assertArrayNotHasKey('integration_id', $context);
    }
}
