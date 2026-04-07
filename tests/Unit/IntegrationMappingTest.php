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

    public function test_upsert_by_external_id_creates_new_model(): void
    {
        $model = $this->integration->upsertByExternalId('EXT-NEW', Integration::class, [
            'provider' => 'created',
            'name' => 'Created',
        ]);

        $this->assertSame('created', $model->provider);
        $this->assertSame('Created', $model->name);
        $this->assertSame('EXT-NEW', $this->integration->findExternalId($model));
    }

    public function test_upsert_by_external_id_updates_existing_model(): void
    {
        $original = Integration::create(['provider' => 'old', 'name' => 'Old']);
        $this->integration->mapExternalId('EXT-UPD', $original);

        $updated = $this->integration->upsertByExternalId('EXT-UPD', Integration::class, [
            'name' => 'Updated',
        ]);

        $this->assertSame($original->id, $updated->id);
        $this->assertSame('Updated', $updated->name);
    }

    public function test_upsert_by_external_id_is_idempotent(): void
    {
        $this->integration->upsertByExternalId('EXT-IDEM', Integration::class, [
            'provider' => 'first',
            'name' => 'First',
        ]);

        $this->integration->upsertByExternalId('EXT-IDEM', Integration::class, [
            'name' => 'Second',
        ]);

        $this->assertCount(1, $this->integration->mappings()->get());
    }

    public function test_resolve_mappings_returns_keyed_collection(): void
    {
        $a = Integration::create(['provider' => 'a', 'name' => 'A']);
        $b = Integration::create(['provider' => 'b', 'name' => 'B']);
        $c = Integration::create(['provider' => 'c', 'name' => 'C']);

        $this->integration->mapExternalId('EXT-A', $a);
        $this->integration->mapExternalId('EXT-B', $b);
        $this->integration->mapExternalId('EXT-C', $c);

        $result = $this->integration->resolveMappings(['EXT-A', 'EXT-B', 'EXT-C'], Integration::class);

        $this->assertCount(3, $result);
        $this->assertSame($a->id, $result->get('EXT-A')?->getKey());
        $this->assertSame($b->id, $result->get('EXT-B')?->getKey());
        $this->assertSame($c->id, $result->get('EXT-C')?->getKey());
    }

    public function test_resolve_mappings_returns_null_for_missing_ids(): void
    {
        $a = Integration::create(['provider' => 'a', 'name' => 'A']);
        $this->integration->mapExternalId('EXT-A', $a);

        $result = $this->integration->resolveMappings(['EXT-A', 'EXT-MISSING'], Integration::class);

        $this->assertCount(2, $result);
        $this->assertSame($a->id, $result->get('EXT-A')?->getKey());
        $this->assertNull($result->get('EXT-MISSING'));
    }

    public function test_resolve_mappings_with_empty_input(): void
    {
        $result = $this->integration->resolveMappings([], Integration::class);

        $this->assertCount(0, $result);
    }
}
