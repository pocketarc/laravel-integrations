<?php

declare(strict_types=1);

namespace Integrations\Tests\Unit;

use Illuminate\Support\Facades\Event;
use Integrations\Enums\HealthStatus;
use Integrations\Events\IntegrationCreated;
use Integrations\Models\Integration;
use Integrations\Tests\TestCase;

class IntegrationTest extends TestCase
{
    public function test_can_create_integration(): void
    {
        $integration = Integration::create([
            'provider' => 'test',
            'name' => 'My Test Integration',
            'credentials' => ['api_key' => 'secret-123'],
            'metadata' => ['region' => 'us-east-1'],
        ]);

        $this->assertDatabaseHas('integrations', ['name' => 'My Test Integration']);
        $this->assertSame('test', $integration->provider);

        $integration->refresh();
        $this->assertTrue($integration->is_active);
        $this->assertSame(HealthStatus::Healthy, $integration->health_status);
    }

    public function test_credentials_are_encrypted(): void
    {
        $integration = Integration::create([
            'provider' => 'test',
            'name' => 'Encrypted Test',
            'credentials' => ['api_key' => 'super-secret'],
        ]);

        $raw = $this->app['db']->table('integrations')
            ->where('id', $integration->id)
            ->value('credentials');

        $this->assertIsString($raw);
        $this->assertStringNotContainsString('super-secret', $raw);

        $integration->refresh();
        $this->assertSame('super-secret', $integration->credentials['api_key']);
    }

    public function test_metadata_is_plain_json(): void
    {
        $integration = Integration::create([
            'provider' => 'test',
            'name' => 'Metadata Test',
            'metadata' => ['region' => 'eu-west-1'],
        ]);

        $integration->refresh();
        $this->assertSame('eu-west-1', $integration->metadata['region']);
    }

    public function test_active_scope(): void
    {
        Integration::create(['provider' => 'test', 'name' => 'Active', 'is_active' => true]);
        Integration::create(['provider' => 'test', 'name' => 'Inactive', 'is_active' => false]);

        $this->assertCount(1, Integration::active()->get());
        $this->assertSame('Active', Integration::active()->first()?->name);
    }

    public function test_for_provider_scope(): void
    {
        Integration::create(['provider' => 'zendesk', 'name' => 'ZD']);
        Integration::create(['provider' => 'github', 'name' => 'GH']);

        $this->assertCount(1, Integration::forProvider('zendesk')->get());
    }

    public function test_due_for_sync_scope(): void
    {
        Integration::create([
            'provider' => 'test',
            'name' => 'Due',
            'sync_interval_minutes' => 15,
            'next_sync_at' => now()->subMinute(),
        ]);

        Integration::create([
            'provider' => 'test',
            'name' => 'Not Due',
            'sync_interval_minutes' => 15,
            'next_sync_at' => now()->addHour(),
        ]);

        Integration::create([
            'provider' => 'test',
            'name' => 'No Sync',
        ]);

        $due = Integration::dueForSync()->get();
        $this->assertCount(1, $due);
        $this->assertSame('Due', $due->first()?->name);
    }

    public function test_dispatches_created_event(): void
    {
        Event::fake(IntegrationCreated::class);

        Integration::create(['provider' => 'test', 'name' => 'New']);

        Event::assertDispatched(IntegrationCreated::class);
    }
}
