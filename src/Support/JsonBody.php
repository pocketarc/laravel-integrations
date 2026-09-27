<?php

declare(strict_types=1);

namespace Integrations\Support;

use JsonException;

use function Safe\json_encode;

final class JsonBody
{
    private const MARKER_PREFIX = '[UNENCODABLE ';

    public static function encode(mixed $value): string
    {
        try {
            return json_encode($value, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            // Keep $value out of the marker. Redactor::redact() returns non-JSON bodies
            // unchanged, so sensitive fields from $value would be stored unredacted.
            return self::MARKER_PREFIX.$e->getMessage().']';
        }
    }

    public static function isUnencodable(?string $body): bool
    {
        return $body !== null && str_starts_with($body, self::MARKER_PREFIX);
    }
}
