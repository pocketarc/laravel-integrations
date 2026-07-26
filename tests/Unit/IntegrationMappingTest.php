<?php

declare(strict_types=1);

namespace Integrations\Tests\Unit;

use Integrations\Exceptions\MappingAlreadyClaimed;
use Integrations\Models\Integration;
use Integrations\Models\IntegrationMapping;
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

    public function test_map_external_id_refuses_to_steal_a_claimed_mapping(): void
    {
        $model1 = Integration::create(['provider' => 'a', 'name' => 'A']);
        $model2 = Integration::create(['provider' => 'b', 'name' => 'B']);

        $this->integration->mapExternalId('EXT-123', $model1);

        try {
            $this->integration->mapExternalId('EXT-123', $model2);
            $this->fail('Expected MappingAlreadyClaimed.');
        } catch (MappingAlreadyClaimed $e) {
            $this->assertSame('EXT-123', $e->externalId);
            $this->assertSame((string) $model1->id, $e->claimedBy);
            $this->assertSame((string) $model2->id, $e->requestedBy);
        }

        $this->assertCount(1, $this->integration->mappings()->get());
        $this->assertSame((string) $model1->id, $this->integration->mappings()->first()?->internal_id);
        $this->assertSame('EXT-123', $this->integration->findExternalId($model1));
    }

    public function test_map_external_id_is_idempotent_for_the_same_model(): void
    {
        $target = Integration::create(['provider' => 'a', 'name' => 'A']);

        $first = $this->integration->mapExternalId('EXT-123', $target);
        $second = $this->integration->mapExternalId('EXT-123', $target);

        $this->assertSame($first->id, $second->id);
        $this->assertCount(1, $this->integration->mappings()->get());
    }

    public function test_map_external_id_allows_the_same_external_id_for_a_different_type(): void
    {
        $other = IntegrationMapping::create([
            'integration_id' => $this->integration->id,
            'external_id' => 'EXT-123',
            'internal_type' => 'App\\Models\\SomethingElse',
            'internal_id' => '999',
        ]);

        $target = Integration::create(['provider' => 'a', 'name' => 'A']);
        $mapping = $this->integration->mapExternalId('EXT-123', $target);

        $this->assertNotSame($mapping->id, $other->id);
        $this->assertSame((string) $target->id, $mapping->internal_id);
        $this->assertCount(2, $this->integration->mappings()->get());
    }

    public function test_remap_external_id_moves_a_claimed_mapping(): void
    {
        $model1 = Integration::create(['provider' => 'a', 'name' => 'A']);
        $model2 = Integration::create(['provider' => 'b', 'name' => 'B']);

        $this->integration->mapExternalId('EXT-123', $model1);
        $this->integration->remapExternalId('EXT-123', $model2);

        $this->assertCount(1, $this->integration->mappings()->get());
        $this->assertSame((string) $model2->id, $this->integration->mappings()->first()?->internal_id);

        $this->assertNull($this->integration->findExternalId($model1));
    }

    public function test_remap_external_id_creates_the_mapping_when_absent(): void
    {
        $target = Integration::create(['provider' => 'a', 'name' => 'A']);

        $this->integration->remapExternalId('EXT-NEW', $target);

        $this->assertSame('EXT-NEW', $this->integration->findExternalId($target));
    }

    public function test_upsert_by_external_id_converges_when_the_claim_is_lost(): void
    {
        // Stands in for the race the lock normally prevents: the mapping is
        // already taken by the time this caller tries to claim it.
        $winner = Integration::create(['provider' => 'winner', 'name' => 'Winner']);
        $this->integration->mapExternalId('EXT-RACE', $winner);

        $before = Integration::query()->count();

        $result = $this->integration->upsertByExternalId('EXT-RACE', Integration::class, [
            'provider' => 'loser',
            'name' => 'Loser',
        ]);

        $this->assertSame($winner->id, $result->getKey(), 'should converge on the row that holds the mapping');
        $this->assertSame('Loser', $result->name, 'the attributes still land');
        $this->assertSame($before, Integration::query()->count(), 'no duplicate row left behind');
        $this->assertCount(1, $this->integration->mappings()->get());
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

    public function test_resolve_mappings_spans_more_than_one_query_chunk(): void
    {
        $count = 501;
        $now = now();

        $rows = [];
        for ($i = 0; $i < $count; $i++) {
            $rows[] = ['provider' => 'bulk', 'name' => "Bulk {$i}", 'created_at' => $now, 'updated_at' => $now];
        }

        foreach (array_chunk($rows, 100) as $chunk) {
            Integration::insert($chunk);
        }

        $targets = Integration::query()->where('provider', 'bulk')->orderBy('id')->get();

        $externalIds = [];
        $mappings = [];

        foreach ($targets as $index => $target) {
            $externalId = "EXT-BULK-{$index}";
            $externalIds[] = $externalId;
            $mappings[] = [
                'integration_id' => $this->integration->id,
                'external_id' => $externalId,
                'internal_type' => $target->getMorphClass(),
                'internal_id' => (string) $target->getKey(),
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        foreach (array_chunk($mappings, 100) as $chunk) {
            IntegrationMapping::insert($chunk);
        }

        $result = $this->integration->resolveMappings($externalIds, Integration::class);

        $this->assertCount($count, $result);
        $this->assertSame($targets->first()?->getKey(), $result->get('EXT-BULK-0')?->getKey());
        $this->assertSame($targets->last()?->getKey(), $result->get('EXT-BULK-500')?->getKey());
        $this->assertSame($targets->get(499)?->getKey(), $result->get('EXT-BULK-499')?->getKey());
    }

    public function test_resolve_mappings_with_empty_input(): void
    {
        $result = $this->integration->resolveMappings([], Integration::class);

        $this->assertCount(0, $result);
    }

    public function test_long_external_ids_round_trip_within_500_char_cap(): void
    {
        $target = Integration::create(['provider' => 'long', 'name' => 'Long']);
        $longId = str_repeat('x', 500);

        $mapping = $this->integration->mapExternalId($longId, $target);

        $this->assertSame($longId, $mapping->external_id);

        $resolved = $this->integration->resolveMapping($longId, Integration::class);

        $this->assertNotNull($resolved);
        $this->assertSame($target->id, $resolved->getKey());
    }
}
