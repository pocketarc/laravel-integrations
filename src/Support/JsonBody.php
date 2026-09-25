<?php

declare(strict_types=1);

namespace Integrations\Support;

use JsonException;

use function Safe\json_encode;

/**
 * JSON-encodes a request or response body for storage, returning an
 * "[UNENCODABLE <exception message>]" marker when json_encode throws.
 */
final class JsonBody
{
    private const MARKER_PREFIX = '[UNENCODABLE ';

    public static function encode(mixed $value): string
    {
        try {
            return json_encode($value, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            return self::MARKER_PREFIX.$e->getMessage().']';
        }
    }

    public static function isUnencodable(?string $body): bool
    {
        return $body !== null && str_starts_with($body, self::MARKER_PREFIX);
    }
}
