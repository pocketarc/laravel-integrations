<?php

declare(strict_types=1);

namespace Integrations\Support;

use function Safe\json_decode;
use function Safe\json_encode;

class Redactor
{
    /**
     * Redact sensitive fields from a JSON string using dot-notation paths.
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

        foreach ($paths as $path) {
            data_set($data, $path, '[REDACTED]');
        }

        return json_encode($data, JSON_THROW_ON_ERROR);
    }
}
