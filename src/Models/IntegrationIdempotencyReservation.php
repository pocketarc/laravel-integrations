<?php

declare(strict_types=1);

namespace Integrations\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Integrations\Support\Config;

/**
 * @property int $id
 * @property int $integration_id
 * @property string $key
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Integration|null $integration
 *
 * @mixin \Eloquent
 */
class IntegrationIdempotencyReservation extends Model
{
    /** @var array<string> */
    protected $guarded = [];

    #[\Override]
    public function getTable(): string
    {
        return Config::tablePrefix().'_idempotency_reservations';
    }

    /** @return BelongsTo<Integration, $this> */
    public function integration(): BelongsTo
    {
        return $this->belongsTo(Integration::class);
    }
}
