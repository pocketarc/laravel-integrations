<?php

declare(strict_types=1);

namespace Integrations\Support;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

use function Safe\preg_match;

/**
 * Builds a driver-appropriate SQL expression that extracts a top-level string
 * value from a JSON column, so aggregation queries can group by a field inside
 * a JSON payload (e.g. the error message) without re-decoding in PHP.
 *
 * The column and key are interpolated into raw SQL, so both are validated
 * against a strict identifier whitelist rather than trusted.
 */
final class JsonPathExtractor
{
    /**
     * The column and key must be compile-time literals, so the returned
     * expression is a literal-string too. The query builder's raw methods
     * (selectRaw/groupByRaw) type their argument as literal-string to keep
     * caller-supplied input out of interpolated SQL; a non-literal here fails
     * that constraint, so the type is carried through rather than widened.
     *
     * @param  literal-string  $column
     * @param  literal-string  $key
     * @param  string|null  $driver  Connection driver name; defaults to the
     *                               active connection's. Injectable so callers
     *                               (and tests) can target a specific driver.
     * @return literal-string
     */
    public static function stringPath(string $column, string $key, ?string $driver = null): string
    {
        self::guardIdentifier($column);
        self::guardIdentifier($key);

        return match ($driver ?? DB::getDriverName()) {
            'pgsql' => "{$column}->>'{$key}'",
            'sqlite' => "json_extract({$column}, '$.{$key}')",
            default => "JSON_UNQUOTE(JSON_EXTRACT({$column}, '$.{$key}'))",
        };
    }

    private static function guardIdentifier(string $value): void
    {
        if (preg_match('/^[A-Za-z0-9_]+$/', $value) !== 1) {
            throw new InvalidArgumentException("Unsafe JSON path identifier: {$value}");
        }
    }
}
