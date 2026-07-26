<?php

declare(strict_types=1);

namespace Integrations\Support;

use InvalidArgumentException;

/**
 * Normalises an Eloquent primary key for storage in `internal_id`.
 *
 * That column is a VARCHAR because the mapping table is polymorphic and has to
 * hold auto-increment ints, UUIDs and ULIDs alike. Comparisons against it are
 * therefore string comparisons, and a key that silently stringified differently
 * on write and read would simply stop resolving.
 */
class ModelKey
{
    public static function toString(mixed $key): string
    {
        if (is_int($key) || is_string($key)) {
            return (string) $key;
        }

        throw new InvalidArgumentException('Model key must be a string or integer.');
    }
}
