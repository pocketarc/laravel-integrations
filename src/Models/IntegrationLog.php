<?php

declare(strict_types=1);

namespace Integrations\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Integrations\Support\Config;

/**
 * @property int $id
 * @property int $integration_id
 * @property int|null $parent_id
 * @property string $operation
 * @property string $direction
 * @property string $status
 * @property string|null $external_id
 * @property string|null $summary
 * @property array<string, mixed>|null $metadata
 * @property string|null $error
 * @property int|null $duration_ms
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 *
 * @method static Builders\IntegrationLogBuilder<static>|IntegrationLog newModelQuery()
 * @method static Builders\IntegrationLogBuilder<static>|IntegrationLog newQuery()
 * @method static Builders\IntegrationLogBuilder<static>|IntegrationLog query()
 * @method static Builders\IntegrationLogBuilder<static>|IntegrationLog successful()
 * @method static Builders\IntegrationLogBuilder<static>|IntegrationLog failed()
 * @method static Builders\IntegrationLogBuilder<static>|IntegrationLog forOperation(string $operation)
 * @method static Builders\IntegrationLogBuilder<static>|IntegrationLog topLevel()
 *
 * @property-read Collection<int, IntegrationLog> $children
 * @property-read int|null $children_count
 * @property-read Integration|null $integration
 * @property-read IntegrationLog|null $parent
 *
 * @method static Builders\IntegrationLogBuilder<static>|IntegrationLog recent(int $hours = 24)
 *
 * @mixin \Eloquent
 */
class IntegrationLog extends Model
{
    /** @var array<string> */
    protected $guarded = [];

    #[\Override]
    public function getTable(): string
    {
        return Config::tablePrefix().'_logs';
    }

    /**
     * @return array<string, string>
     */
    #[\Override]
    protected function casts(): array
    {
        return [
            'metadata' => 'json',
            'duration_ms' => 'integer',
        ];
    }

    /** @return BelongsTo<Integration, $this> */
    public function integration(): BelongsTo
    {
        return $this->belongsTo(Integration::class);
    }

    /** @return BelongsTo<self, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /** @return HasMany<self, $this> */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    /**
     * @param  Builder  $query
     * @return Builders\IntegrationLogBuilder<IntegrationLog>
     */
    #[\Override]
    public function newEloquentBuilder($query): Builders\IntegrationLogBuilder
    {
        return new Builders\IntegrationLogBuilder($query);
    }
}
