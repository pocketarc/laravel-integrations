<?php

declare(strict_types=1);

namespace Integrations\Support;

use Carbon\CarbonInterface;

class BinaryGuard
{
    /**
     * Return a UTF-8-safe representation of $value for storage in a utf8mb4 text
     * column. `null` and valid UTF-8 strings pass through unchanged; non-UTF-8
     * bytes are replaced with "[BINARY <length> bytes sha256=<hash>]" so the
     * audit row stays useful without crashing the INSERT.
     */
    public static function sanitize(?string $value): ?string
    {
        if ($value === null || mb_check_encoding($value, 'UTF-8')) {
            return $value;
        }

        return sprintf(
            '[BINARY %d bytes sha256=%s]',
            mb_strlen($value, '8bit'),
            hash('sha256', $value),
        );
    }

    public static function isBinary(?string $value): bool
    {
        return $value !== null && ! mb_check_encoding($value, 'UTF-8');
    }

    /**
     * Sanitize a response body for utf8mb4 storage, and drop $cacheFor when the
     * body was binary. The cache layer can't decode binary, so the row
     * shouldn't become a cache source.
     *
     * @return array{0: ?string, 1: ?CarbonInterface}
     */
    public static function sanitizeResponseBody(?string $value, ?CarbonInterface $cacheFor): array
    {
        return self::isBinary($value)
            ? [self::sanitize($value), null]
            : [$value, $cacheFor];
    }
}
