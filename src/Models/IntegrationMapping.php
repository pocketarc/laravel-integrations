<?php

declare(strict_types=1);

namespace Integrations\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $integration_id
 * @property string $external_id
 * @property string $internal_type
 * @property string $internal_id
 * @property array<string, mixed>|null $metadata
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|IntegrationMapping newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|IntegrationMapping newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|IntegrationMapping query()
 *
 * @property-read Integration|null $integration
 * @property-read Model $internalModel
 *
 * @mixin \Eloquent
 */
class IntegrationMapping extends Model
{
    /** @var array<string> */
    protected $guarded = [];

    public function getTable(): string
    {
        /** @var string $prefix */
        $prefix = config('integrations.table_prefix', 'integration');

        return $prefix.'_mappings';
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'metadata' => 'json',
        ];
    }

    /** @return BelongsTo<Integration, $this> */
    public function integration(): BelongsTo
    {
        return $this->belongsTo(Integration::class);
    }

    /** @return MorphTo<Model, $this> */
    public function internalModel(): MorphTo
    {
        return $this->morphTo(name: 'internal');
    }
}
