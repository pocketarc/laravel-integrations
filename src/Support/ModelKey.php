<?php

declare(strict_types=1);

namespace Integrations\Support;

use InvalidArgumentException;

/** Normalises an Eloquent primary key for storage in `internal_id`. */
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
