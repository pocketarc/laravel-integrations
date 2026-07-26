<?php

declare(strict_types=1);

namespace Integrations\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Integrations\Exceptions\MappingAlreadyClaimed;
use Integrations\Models\Integration;
use Integrations\Models\IntegrationMapping;
use Integrations\Support\Config;
use Integrations\Support\ModelKey;

/**
 * Translation between an upstream system's IDs and local model rows, backed by
 * the `integration_mappings` table.
 *
 * Extracted from the Integration model because it's a self-contained concern
 * with its own invariant: one external ID maps to exactly one local row per
 * (integration, model type), and nothing may take that mapping away from the
 * row that holds it without saying so.
 *
 * @phpstan-require-extends Integration
 */
trait MapsExternalIds
{
    /**
     * Claim an external ID for a local model.
     *
     * Atomic: the insert leans on the unique index over
     * `(integration_id, external_id, internal_type)` rather than a
     * read-then-write, so two callers racing the same external ID both end up
     * looking at one row instead of both inserting one.
     *
     * Refuses to move a mapping another model already holds. Silently
     * re-pointing (the behaviour before 6.0) orphaned the previous model: it
     * kept every column it had, so it still satisfied ordinary queries, but
     * {@see findExternalId()} returned null for it and nothing could address it
     * upstream again. Call {@see remapExternalId()} when moving it is the point.
     *
     * @throws MappingAlreadyClaimed when a different model already holds it
     */
    public function mapExternalId(string $externalId, Model $internalModel): IntegrationMapping
    {
        $internalType = $internalModel->getMorphClass();
        $internalId = ModelKey::toString($internalModel->getKey());

        $mapping = $this->mappings()->createOrFirst(
            [
                'external_id' => $externalId,
                'internal_type' => $internalType,
            ],
            [
                'internal_id' => $internalId,
            ],
        );

        if ($mapping->internal_id !== $internalId) {
            throw new MappingAlreadyClaimed(
                integrationId: $this->id,
                externalId: $externalId,
                internalType: $internalType,
                claimedBy: $mapping->internal_id,
                requestedBy: $internalId,
            );
        }

        return $mapping;
    }

    /**
     * Move an external ID's mapping to a different local model, or create it if
     * absent. The deliberate counterpart to {@see mapExternalId()}: same write,
     * but named so a re-point is something a caller asked for rather than
     * something a lost race did quietly.
     *
     * The model that held the mapping keeps its row and loses its external ID.
     * Reconciling or deleting it is the caller's job; this method won't.
     */
    public function remapExternalId(string $externalId, Model $internalModel): IntegrationMapping
    {
        return $this->mappings()->updateOrCreate(
            [
                'external_id' => $externalId,
                'internal_type' => $internalModel->getMorphClass(),
            ],
            [
                'internal_id' => ModelKey::toString($internalModel->getKey()),
            ],
        );
    }

    /**
     * @template TModel of Model
     *
     * @param  class-string<TModel>  $internalType
     * @return TModel|null
     */
    public function resolveMapping(string $externalId, string $internalType): ?Model
    {
        $mapping = $this->mappings()
            ->where('external_id', $externalId)
            ->where('internal_type', (new $internalType)->getMorphClass())
            ->first();

        if ($mapping === null) {
            return null;
        }

        $model = (new $internalType)->newQuery()->find($mapping->internal_id);

        if (! $model instanceof $internalType) {
            return null;
        }

        return $model;
    }

    public function findExternalId(Model $internalModel): ?string
    {
        $mapping = $this->mappings()
            ->where('internal_type', $internalModel->getMorphClass())
            ->where('internal_id', ModelKey::toString($internalModel->getKey()))
            ->first();

        return $mapping?->external_id;
    }

    /**
     * Create or update the local model an external ID maps to.
     *
     * Serialised per (integration, model type, external ID) so two workers
     * syncing the same upstream record don't each insert a row. Only one of
     * those rows could hold the mapping, and the other was left behind
     * unreachable.
     *
     * @template TModel of Model
     *
     * @param  class-string<TModel>  $modelClass
     * @param  array<string, mixed>  $attributes
     * @return TModel
     */
    public function upsertByExternalId(string $externalId, string $modelClass, array $attributes): Model
    {
        $lock = Cache::lock(
            Config::cachePrefix().":mapping:{$this->id}:".(new $modelClass)->getMorphClass().":{$externalId}",
            Config::mappingLockTtl(),
        );

        try {
            $lock->block(Config::mappingLockWait());

            return $this->upsertByExternalIdUnlocked($externalId, $modelClass, $attributes);
        } finally {
            $lock->release();
        }
    }

    /**
     * The body of {@see upsertByExternalId()}, minus the lock.
     *
     * The lock is what normally keeps two workers off the same external ID, but
     * it can only do that when the cache driver is shared: on the `array`
     * driver it's per-process and buys nothing. So the collision is still
     * handled rather than assumed away. Losing the claim means another worker
     * created the model first and its row is the one the mapping points at, so
     * roll our duplicate back and carry on with theirs.
     *
     * @template TModel of Model
     *
     * @param  class-string<TModel>  $modelClass
     * @param  array<string, mixed>  $attributes
     * @return TModel
     */
    private function upsertByExternalIdUnlocked(string $externalId, string $modelClass, array $attributes): Model
    {
        $existing = $this->resolveMapping($externalId, $modelClass);

        if ($existing !== null) {
            $existing->update($attributes);

            return $existing->refresh();
        }

        DB::beginTransaction();

        try {
            $model = new $modelClass($attributes);
            $model->save();
            $this->mapExternalId($externalId, $model);
            DB::commit();
        } catch (MappingAlreadyClaimed $e) {
            DB::rollBack();

            return $this->adoptClaimedModel($externalId, $modelClass, $attributes, $e);
        } catch (\Throwable $e) {
            DB::rollBack();

            throw $e;
        }

        return $model;
    }

    /**
     * Carry on with the row that won the mapping, applying the attributes we
     * were going to write to our own.
     *
     * @template TModel of Model
     *
     * @param  class-string<TModel>  $modelClass
     * @param  array<string, mixed>  $attributes
     * @return TModel
     *
     * @throws MappingAlreadyClaimed when the winner can't be resolved
     */
    private function adoptClaimedModel(string $externalId, string $modelClass, array $attributes, MappingAlreadyClaimed $claim): Model
    {
        $winner = $this->resolveMapping($externalId, $modelClass);

        if ($winner === null) {
            // The mapping was claimed and then vanished, or it points at a row
            // of another type. Either way there's nothing to converge on, so
            // surface the claim rather than silently retrying.
            throw $claim;
        }

        $winner->update($attributes);

        return $winner->refresh();
    }

    /**
     * @template TModel of Model
     *
     * @param  list<string>  $externalIds
     * @param  class-string<TModel>  $internalType
     * @return Collection<string, TModel|null>
     */
    public function resolveMappings(array $externalIds, string $internalType): Collection
    {
        /** @var array<string, TModel|null> $result */
        $result = [];

        if ($externalIds === []) {
            return collect($result);
        }

        $morphClass = (new $internalType)->getMorphClass();

        $mappings = $this->mappings()
            ->whereIn('external_id', $externalIds)
            ->where('internal_type', $morphClass)
            ->get();

        $internalIds = $mappings->pluck('internal_id')->unique()->values()->all();

        $instance = new $internalType;
        $modelsByKey = $instance->newQuery()
            ->whereIn($instance->getKeyName(), $internalIds)
            ->get()
            ->keyBy(fn (Model $model): string => ModelKey::toString($model->getKey()));

        foreach ($externalIds as $externalId) {
            $mapping = $mappings->firstWhere('external_id', $externalId);
            $model = $mapping !== null ? $modelsByKey->get($mapping->internal_id) : null;
            $result[$externalId] = $model instanceof $internalType ? $model : null;
        }

        return collect($result);
    }
}
