<?php

declare(strict_types=1);

namespace Integrations\Support;

use function Safe\json_decode;

class Redactor
{
    /**
     * Redact sensitive fields from a JSON string using dot-notation paths. A
     * `*` segment matches every key at its level. If the JSON contains none of
     * the paths, the input string is returned unchanged.
     *
     * @param  list<string>  $paths
     */
    public static function redact(string $json, array $paths): string
    {
        if ($paths === []) {
            return $json;
        }

        try {
            $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $json;
        }

        if (! is_array($data)) {
            return $json;
        }

        $redacted = false;

        foreach ($paths as $path) {
            [$data, $matched] = self::redactPath($data, explode('.', $path));
            $redacted = $redacted || $matched;
        }

        return $redacted ? JsonBody::encode($data) : $json;
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @param  list<string>  $segments
     * @return array{array<array-key, mixed>, bool}
     */
    private static function redactPath(array $data, array $segments): array
    {
        $segment = array_shift($segments);
        if ($segment === null) {
            return [$data, false];
        }

        $redacted = false;

        foreach ($segment === '*' ? array_keys($data) : [$segment] as $key) {
            if (! array_key_exists($key, $data)) {
                continue;
            }

            if ($segments === []) {
                $data[$key] = '[REDACTED]';
                $redacted = true;
            } elseif (is_array($data[$key])) {
                [$data[$key], $matched] = self::redactPath($data[$key], $segments);
                $redacted = $redacted || $matched;
            }
        }

        return [$data, $redacted];
    }
}
