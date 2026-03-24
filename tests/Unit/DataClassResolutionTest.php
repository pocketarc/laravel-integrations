<?php

declare(strict_types=1);

namespace Integrations\Tests\Unit;

use Integrations\IntegrationManager;
use Integrations\Models\Integration;
use Integrations\Tests\Fixtures\TestCredentials;
use Integrations\Tests\Fixtures\TestDataProvider;
use Integrations\Tests\Fixtures\TestMetadata;
use Integrations\Tests\Fixtures\TestProvider;
use Integrations\Tests\TestCase;

class DataClassResolutionTest extends TestCase
{
    public function test_provider_declares_data_class(): void
    {
        $manager = app(IntegrationManager::class);
        $manager->register('typed', TestDataProvider::class);

        $integration = Integration::create([
            'provider' => 'typed',
            'name' => 'Typed Integration',
            'credentials' => ['api_key' => 'secret-123'],
            'metadata' => ['region' => 'eu-west-1'],
            'is_active' => true,
            'health_status' => 'healthy',
            'consecutive_failures' => 0,
        ]);

        $integration->refresh();

        $this->assertInstanceOf(TestCredentials::class, $integration->credentials);
        $this->assertSame('secret-123', $integration->credentials->api_key);

        $this->assertInstanceOf(TestMetadata::class, $integration->metadata);
        $this->assertSame('eu-west-1', $integration->metadata->region);
    }

    public function test_provider_returns_null_gives_plain_array(): void
    {
        $manager = app(IntegrationManager::class);
        $manager->register('test', TestProvider::class);

        $integration = Integration::create([
            'provider' => 'test',
            'name' => 'Plain Array Integration',
            'credentials' => ['api_key' => 'plain'],
            'metadata' => ['some' => 'data'],
            'is_active' => true,
            'health_status' => 'healthy',
            'consecutive_failures' => 0,
        ]);

        $integration->refresh();

        $this->assertIsArray($integration->credentials);
        $this->assertSame('plain', $integration->credentials['api_key']);

        $this->assertIsArray($integration->metadata);
        $this->assertSame('data', $integration->metadata['some']);
    }

    public function test_orphaned_provider_falls_back_gracefully(): void
    {
        $integration = Integration::create([
            'provider' => 'nonexistent',
            'name' => 'Orphaned Integration',
            'credentials' => ['api_key' => 'orphaned'],
            'metadata' => ['key' => 'value'],
            'is_active' => true,
            'health_status' => 'healthy',
            'consecutive_failures' => 0,
        ]);

        $integration->refresh();

        $this->assertIsArray($integration->credentials);
        $this->assertSame('orphaned', $integration->credentials['api_key']);

        $this->assertIsArray($integration->metadata);
        $this->assertSame('value', $integration->metadata['key']);
    }
}
