<?php

declare(strict_types=1);

namespace Integrations\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Integrations\Enums\HealthStatus;
use Integrations\Listeners\RecordIntegrationIncidents;
use Integrations\Support\Config;

/**
 * A durable record of one period an integration was in trouble. The package
 * opens an incident from its own health, circuit, and sync-staleness
 * state-change events and closes
 * it on recovery, collapsing flapping into a single open row per integration
 * and tracking the worst severity reached. Unlike the cache-only circuit state,
 * this survives a cache flush, so "incidents since T" is answerable.
 *
 * Written by {@see RecordIntegrationIncidents}.
 *
 * @property int $id
 * @property int $integration_id
 * @property string $status
 * @property string $source
 * @property string $reason
 * @property HealthStatus $peak_severity
 * @property Carbon $opened_at
 * @property Carbon|null $last_error_at
 * @property Carbon|null $closed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Integration|null $integration
 *
 * @method static Builders\IntegrationIncidentBuilder<static>|IntegrationIncident newModelQuery()
 * @method static Builders\IntegrationIncidentBuilder<static>|IntegrationIncident newQuery()
 * @method static Builders\IntegrationIncidentBuilder<static>|IntegrationIncident query()
 * @method static Builders\IntegrationIncidentBuilder<static>|IntegrationIncident open()
 * @method static Builders\IntegrationIncidentBuilder<static>|IntegrationIncident closed()
 * @method static Builders\IntegrationIncidentBuilder<static>|IntegrationIncident forIntegration(int $integrationId)
 * @method static Builders\IntegrationIncidentBuilder<static>|IntegrationIncident since(\Carbon\CarbonInterface $since)
 *
 * @mixin \Eloquent
 */
class IntegrationIncident extends Model
{
    public const STATUS_OPEN = 'open';

    public const STATUS_CLOSED = 'closed';

    public const SOURCE_HEALTH = 'health';

    public const SOURCE_CIRCUIT = 'circuit';

    public const SOURCE_SYNC = 'sync';

    /** @var array<string> */
    protected $guarded = [];

    #[\Override]
    public function getTable(): string
    {
        return Config::tablePrefix().'_incidents';
    }

    /**
     * @return array<string, string>
     */
    #[\Override]
    protected function casts(): array
    {
        return [
            'peak_severity' => HealthStatus::class,
            'opened_at' => 'datetime',
            'last_error_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Integration, $this> */
    public function integration(): BelongsTo
    {
        return $this->belongsTo(Integration::class);
    }

    public function isOpen(): bool
    {
        return $this->status === self::STATUS_OPEN;
    }

    /**
     * @param  Builder  $query
     * @return Builders\IntegrationIncidentBuilder<IntegrationIncident>
     */
    #[\Override]
    public function newEloquentBuilder($query): Builders\IntegrationIncidentBuilder
    {
        return new Builders\IntegrationIncidentBuilder($query);
    }
}
