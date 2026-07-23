<?php

declare(strict_types=1);

namespace Integrations\Support;

use function Safe\preg_match;

/**
 * Matches endpoint patterns for both the testing fake's expectations and
 * providers opting endpoints out of response-body logging. One definition, so
 * a pattern written against a fake keeps its meaning in production config.
 *
 * `*` matches within a path segment and never across `/`, and an optional
 * `METHOD:` prefix narrows a pattern to one verb.
 */
class EndpointPattern
{
    private const HTTP_METHODS = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD', 'OPTIONS'];

    /**
     * Split a `METHOD:endpoint` key into its parts. A prefix that is not an
     * HTTP verb is left alone: it is part of the endpoint.
     *
     * @return array{string|null, string}
     */
    public static function parse(string $key): array
    {
        $colonPos = mb_strpos($key, ':');

        if ($colonPos === false) {
            return [null, $key];
        }

        $prefix = mb_strtoupper(mb_substr($key, 0, $colonPos));

        if (in_array($prefix, self::HTTP_METHODS, true)) {
            return [$prefix, mb_substr($key, $colonPos + 1)];
        }

        return [null, $key];
    }

    /**
     * Whether an endpoint matches a pattern, `*` matching any run of
     * characters except `/`.
     */
    public static function matches(string $pattern, string $endpoint): bool
    {
        $regex = '#^'.str_replace('\*', '[^/]*', preg_quote($pattern, '#')).'$#u';

        return preg_match($regex, $endpoint) === 1;
    }

    /**
     * Whether an endpoint and method match any of the given patterns.
     *
     * @param  array<mixed>  $patterns
     */
    public static function matchesAny(array $patterns, string $endpoint, string $method): bool
    {
        $method = mb_strtoupper($method);

        foreach ($patterns as $key) {
            // A provider hands this list back from its own code, so a stray
            // non-string entry is a provider bug: skip it rather than fail the
            // live request it was gating.
            if (! is_string($key)) {
                continue;
            }

            [$patternMethod, $pattern] = self::parse($key);

            if ($patternMethod !== null && $patternMethod !== $method) {
                continue;
            }

            if ($pattern === $endpoint || self::matches($pattern, $endpoint)) {
                return true;
            }
        }

        return false;
    }
}
