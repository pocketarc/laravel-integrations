<?php

declare(strict_types=1);

namespace Integrations\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Integrations\Console\FindOrphansCommand;
use Integrations\Exceptions\MappingAlreadyClaimed;
use Integrations\Models\Integration;
use Integrations\Models\IntegrationMapping;
use Integrations\Support\Config;
use Integrations\Support\ModelKey;

/**
 * Translation between an upstream system's IDs and local model rows, backed by
 * the `integration_mappings` table.
 *
 * One external ID maps to exactly one local row per (integration, model type).
 * A row that loses its mapping is unreachable but otherwise intact, so it keeps
 * satisfying ordinary queries: nothing detects it except
 * {@see FindOrphansCommand}.
 *
 * @phpstan-require-extends Integration
 */
trait MapsExternalIds
{
    /**
     * How many IDs one batch query may bind. Old SQLite builds cap a statement
     * at 999 parameters, and a caller resolving a whole upstream page can pass
     * more than that in a single array.
     */
    private const MAPPING_QUERY_CHUNK = 500;

    /**
     * Claim an external ID for a local model. Idempotent for the same model.
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
     * absent.
     *
     * The displaced model keeps its row and loses its external ID. Reconciling
     * or deleting it is the caller's job.
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
     * The caller's lock only serialises across processes on a shared cache
     * driver; on `array` it is per-process. The MappingAlreadyClaimed branch is
     * what makes this correct without one.
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

        $internalIdsByExternalId = $this->mappedInternalIds($externalIds, $morphClass);
        $modelsByKey = $this->loadModelsByKey($internalType, array_values(array_unique($internalIdsByExternalId)));

        foreach ($externalIds as $externalId) {
            $internalId = $internalIdsByExternalId[$externalId] ?? null;
            $result[$externalId] = $internalId === null ? null : ($modelsByKey[$internalId] ?? null);
        }

        return collect($result);
    }

    /**
     * @param  list<string>  $externalIds
     * @return array<string, string>
     */
    private function mappedInternalIds(array $externalIds, string $morphClass): array
    {
        $internalIds = [];

        foreach (array_chunk($externalIds, self::MAPPING_QUERY_CHUNK) as $chunk) {
            $mappings = $this->mappings()
                ->whereIn('external_id', $chunk)
                ->where('internal_type', $morphClass)
                ->get();

            foreach ($mappings as $mapping) {
                $internalIds[$mapping->external_id] = $mapping->internal_id;
            }
        }

        return $internalIds;
    }

    /**
     * @template TModel of Model
     *
     * @param  class-string<TModel>  $internalType
     * @param  list<string>  $keys
     * @return array<string, TModel>
     */
    private function loadModelsByKey(string $internalType, array $keys): array
    {
        $modelsByKey = [];
        $keyName = (new $internalType)->getKeyName();

        foreach (array_chunk($keys, self::MAPPING_QUERY_CHUNK) as $chunk) {
            $models = $internalType::query()
                ->whereIn($keyName, $chunk)
                ->get();

            foreach ($models as $model) {
                if ($model instanceof $internalType) {
                    $modelsByKey[ModelKey::toString($model->getKey())] = $model;
                }
            }
        }

        return $modelsByKey;
    }
}
