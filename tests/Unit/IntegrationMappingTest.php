<?php

declare(strict_types=1);

namespace Integrations\Tests\Unit;

use Integrations\Models\Integration;
use Integrations\Tests\TestCase;

class IntegrationMappingTest extends TestCase
{
    private Integration $integration;

    protected function setUp(): void
    {
        parent::setUp();

        $this->integration = Integration::create([
            'provider' => 'test',
            'name' => 'Test',
        ]);
    }

    public function test_map_external_id(): void
    {
        $target = Integration::create(['provider' => 'other', 'name' => 'Other']);

        $mapping = $this->integration->mapExternalId('EXT-123', $target);

        $this->assertSame('EXT-123', $mapping->external_id);
        $this->assertSame((string) $target->id, $mapping->internal_id);
    }

    public function test_map_external_id_upserts_same_type(): void
    {
        $model1 = Integration::create(['provider' => 'a', 'name' => 'A']);
        $model2 = Integration::create(['provider' => 'b', 'name' => 'B']);

        $this->integration->mapExternalId('EXT-123', $model1);
        $this->integration->mapExternalId('EXT-123', $model2);

        // Same external_id + same internal_type = upsert, so internal_id gets updated
        $this->assertCount(1, $this->integration->mappings()->get());
        $this->assertSame((string) $model2->id, $this->integration->mappings()->first()?->internal_id);
    }

    public function test_find_external_id(): void
    {
        $target = Integration::create(['provider' => 'target', 'name' => 'Target']);
        $this->integration->mapExternalId('EXT-456', $target);

        $externalId = $this->integration->findExternalId($target);

        $this->assertSame('EXT-456', $externalId);
    }

    public function test_find_external_id_returns_null_when_not_found(): void
    {
        $target = Integration::create(['provider' => 'target', 'name' => 'Target']);

        $externalId = $this->integration->findExternalId($target);

        $this->assertNull($externalId);
    }

    public function test_resolve_mapping(): void
    {
        $target = Integration::create(['provider' => 'target', 'name' => 'Target']);
        $this->integration->mapExternalId('EXT-789', $target);

        $resolved = $this->integration->resolveMapping('EXT-789', Integration::class);

        $this->assertNotNull($resolved);
        $this->assertSame($target->id, $resolved->getKey());
    }

    public function test_resolve_mapping_returns_null_when_not_found(): void
    {
        $resolved = $this->integration->resolveMapping('NOPE', Integration::class);

        $this->assertNull($resolved);
    }
}
