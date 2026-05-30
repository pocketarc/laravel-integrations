<?php

declare(strict_types=1);

namespace Integrations\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Integrations\Support\Config;

/**
 * One row per item dispatched during a sync run. Tracks whether the item's
 * listeners completed, so the sync cursor only advances past finished items.
 *
 * See the `ProcessSyncItem` and `FinaliseSyncRun` jobs.
 *
 * @property int $id
 * @property int $integration_id
 * @property string|null $batch_id
 * @property int|null $sync_log_id
 * @property string $event_class
 * @property string|null $external_id
 * @property mixed $checkpoint_value
 * @property string $status
 * @property string|null $error
 * @property int $attempts
 * @property Carbon|null $completed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Integration|null $integration
 * @property-read IntegrationLog|null $syncLog
 *
 * @method static Builders\IntegrationSyncItemBuilder<static>|IntegrationSyncItem newModelQuery()
 * @method static Builders\IntegrationSyncItemBuilder<static>|IntegrationSyncItem newQuery()
 * @method static Builders\IntegrationSyncItemBuilder<static>|IntegrationSyncItem query()
 * @method static Builders\IntegrationSyncItemBuilder<static>|IntegrationSyncItem pending()
 * @method static Builders\IntegrationSyncItemBuilder<static>|IntegrationSyncItem processing()
 * @method static Builders\IntegrationSyncItemBuilder<static>|IntegrationSyncItem successful()
 * @method static Builders\IntegrationSyncItemBuilder<static>|IntegrationSyncItem failed()
 * @method static Builders\IntegrationSyncItemBuilder<static>|IntegrationSyncItem skipped()
 * @method static Builders\IntegrationSyncItemBuilder<static>|IntegrationSyncItem inFlight()
 * @method static Builders\IntegrationSyncItemBuilder<static>|IntegrationSyncItem forBatch(string $batchId)
 * @method static Builders\IntegrationSyncItemBuilder<static>|IntegrationSyncItem forSyncLog(int $syncLogId)
 * @method static Builders\IntegrationSyncItemBuilder<static>|IntegrationSyncItem forIntegration(int $integrationId)
 *
 * @mixin \Eloquent
 */
class IntegrationSyncItem extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_SUCCESS = 'success';

    public const STATUS_FAILED = 'failed';

    public const STATUS_SKIPPED = 'skipped';

    /** @var array<string> */
    protected $guarded = [];

    #[\Override]
    public function getTable(): string
    {
        return Config::tablePrefix().'_sync_items';
    }

    /**
     * @return array<string, string>
     */
    #[\Override]
    protected function casts(): array
    {
        return [
            'checkpoint_value' => 'json',
            'attempts' => 'integer',
            'completed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Integration, $this> */
    public function integration(): BelongsTo
    {
        return $this->belongsTo(Integration::class);
    }

    /** @return BelongsTo<IntegrationLog, $this> */
    public function syncLog(): BelongsTo
    {
        return $this->belongsTo(IntegrationLog::class, 'sync_log_id');
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, [
            self::STATUS_SUCCESS,
            self::STATUS_FAILED,
            self::STATUS_SKIPPED,
        ], true);
    }

    /**
     * @param  Builder  $query
     * @return Builders\IntegrationSyncItemBuilder<IntegrationSyncItem>
     */
    #[\Override]
    public function newEloquentBuilder($query): Builders\IntegrationSyncItemBuilder
    {
        return new Builders\IntegrationSyncItemBuilder($query);
    }
}
