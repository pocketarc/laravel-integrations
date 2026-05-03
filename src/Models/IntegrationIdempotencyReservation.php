<?php

declare(strict_types=1);

namespace Integrations\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Integrations\Support\Config;
use InvalidArgumentException;

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
    /**
     * Maximum length, in characters, of the application-supplied
     * reservation key. Bounded by the underlying VARCHAR column's index
     * limit on MySQL with utf8mb4 (191 chars x 4 bytes = 764 bytes,
     * fits within InnoDB's 767-byte index prefix). Must match the
     * `string('key', ...)` length declared in the migration.
     */
    public const MAX_KEY_LENGTH = 191;

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

    /**
     * Validate that a key is acceptable for insertion. Throws
     * InvalidArgumentException up-front so callers get a domain error
     * instead of a low-level DB exception when the key is empty or
     * longer than the underlying column's index can hold.
     */
    public static function validateKey(string $key): void
    {
        if ($key === '') {
            throw new InvalidArgumentException('Reservation key must not be empty.');
        }

        $length = mb_strlen($key);
        if ($length > self::MAX_KEY_LENGTH) {
            throw new InvalidArgumentException(sprintf(
                'Reservation key must be at most %d characters; got %d.',
                self::MAX_KEY_LENGTH,
                $length,
            ));
        }
    }
}
