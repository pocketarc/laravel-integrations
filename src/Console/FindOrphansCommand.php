<?php

declare(strict_types=1);

namespace Integrations\Console;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Integrations\Models\Integration;
use Integrations\Models\IntegrationMapping;
use Integrations\Support\ModelKey;

/**
 * Finds local rows of a mapped model that have no external ID.
 *
 * Before 6.0 a lost race could leave one: two workers upserting the same
 * external ID each inserted a row, the second took the mapping, and the first
 * was abandoned with every column intact. It kept satisfying ordinary queries,
 * so the only symptom was that nothing could address it upstream. Consumers hit
 * this as work that was selected, attempted, and failed on every cycle.
 *
 * The comparison runs in chunks over IDs rather than as a SQL join, because
 * `internal_id` is a VARCHAR holding keys of any type and joining it to an
 * integer primary key needs a cast, which on MySQL can fail outright on a
 * collation mismatch.
 */
class FindOrphansCommand extends Command
{
    protected $signature = 'integrations:find-orphans
                            {model : Fully-qualified model class, e.g. "App\\Models\\ZendeskTicket"}
                            {--integration= : Only check mappings for this integration id}
                            {--limit=50 : Maximum rows to list}';

    protected $description = 'List rows of a mapped model that have no external ID mapping.';

    /** Rows read per pass. Keeps the id set that goes into the mapping lookup bounded. */
    private const CHUNK = 1000;

    public function handle(): int
    {
        $modelClass = $this->argument('model');

        if (! is_string($modelClass) || ! class_exists($modelClass) || ! is_subclass_of($modelClass, Model::class)) {
            $this->error('The model argument must be a fully-qualified Eloquent model class.');

            return self::FAILURE;
        }

        $integrationId = $this->resolveIntegrationId();
        if ($integrationId === false) {
            return self::FAILURE;
        }

        $limit = $this->resolveLimit();
        if ($limit === false) {
            return self::FAILURE;
        }

        $orphans = $this->collectOrphans($modelClass, $integrationId, $limit);

        if ($orphans === []) {
            $this->info('No orphaned '.class_basename($modelClass).' rows: every one has a mapping.');

            return self::SUCCESS;
        }

        $this->table(['ID', 'Created'], $orphans);
        $this->newLine();
        $this->line(count($orphans).' row(s) with no external ID.');
        $this->line('Each is unreachable upstream. Restore its integration_mappings row, or merge it into the row that kept the mapping.');

        return self::SUCCESS;
    }

    /**
     * @param  class-string<Model>  $modelClass
     * @return list<array{0: string, 1: string}>
     */
    private function collectOrphans(string $modelClass, ?int $integrationId, int $limit): array
    {
        $model = new $modelClass;
        $morphClass = $model->getMorphClass();
        $keyName = $model->getKeyName();

        $orphans = [];

        $modelClass::query()
            ->orderBy($keyName)
            ->chunkById(self::CHUNK, function (mixed $rows) use (&$orphans, $morphClass, $integrationId, $limit): bool {
                /** @var Collection<int, Model> $rows */
                $keys = $rows->map(fn (Model $row): string => ModelKey::toString($row->getKey()))->all();

                $mapped = $this->mappedKeys($morphClass, array_values($keys), $integrationId);

                foreach ($rows as $row) {
                    $key = ModelKey::toString($row->getKey());

                    if (array_key_exists($key, $mapped)) {
                        continue;
                    }

                    $createdAt = $row->getAttribute('created_at');
                    $orphans[] = [
                        $key,
                        $createdAt instanceof \DateTimeInterface ? $createdAt->format('Y-m-d H:i:s') : '-',
                    ];

                    if (count($orphans) >= $limit) {
                        return false;
                    }
                }

                return true;
            }, $keyName);

        return $orphans;
    }

    /**
     * @param  list<string>  $keys
     * @return array<string, true>
     */
    private function mappedKeys(string $morphClass, array $keys, ?int $integrationId): array
    {
        if ($keys === []) {
            return [];
        }

        $query = IntegrationMapping::query()
            ->where('internal_type', $morphClass)
            ->whereIn('internal_id', $keys);

        if ($integrationId !== null) {
            $query->where('integration_id', $integrationId);
        }

        $mapped = [];
        foreach ($query->pluck('internal_id') as $internalId) {
            if (is_string($internalId)) {
                $mapped[$internalId] = true;
            }
        }

        return $mapped;
    }

    /**
     * @return int|null|false null when unfiltered, false when the option is invalid
     */
    private function resolveIntegrationId(): int|null|false
    {
        $option = $this->option('integration');

        if (! is_string($option) || $option === '') {
            return null;
        }

        if (! ctype_digit($option) || (int) $option <= 0) {
            $this->error('The --integration option must be a positive integer id.');

            return false;
        }

        if (Integration::query()->whereKey((int) $option)->doesntExist()) {
            $this->error("No integration with id {$option}.");

            return false;
        }

        return (int) $option;
    }

    private function resolveLimit(): int|false
    {
        $option = $this->option('limit');

        if (! is_string($option) || ! ctype_digit($option) || (int) $option <= 0) {
            $this->error('The --limit option must be a positive integer.');

            return false;
        }

        return (int) $option;
    }
}
