<?php

declare(strict_types=1);

namespace Integrations\Support;

use GuzzleHttp\Exception\RequestException as GuzzleRequestException;
use Illuminate\Http\Client\RequestException as LaravelRequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Http\JsonResponse;
use JsonException;
use Psr\Http\Message\ResponseInterface;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

use function Safe\json_decode;
use function Safe\json_encode;

final class ResponseHelper
{
    /**
     * Extract the HTTP status code from an exception, if available.
     */
    public static function extractStatusCode(\Throwable $e): ?int
    {
        for ($current = $e; $current !== null; $current = $current->getPrevious()) {
            if ($current instanceof LaravelRequestException) {
                return $current->response->status();
            }

            if ($current instanceof GuzzleRequestException && $current->getResponse() !== null) {
                return $current->getResponse()->getStatusCode();
            }

            if ($current instanceof HttpExceptionInterface) {
                return $current->getStatusCode();
            }

            $duckTyped = self::duckTypeStatusCode($current);
            if ($duckTyped !== null) {
                return $duckTyped;
            }
        }

        return null;
    }

    /**
     * Best-effort status extraction for SDK exceptions that don't implement
     * the HTTP-client interfaces above. Tries the common accessor names
     * (Stripe's `getHttpStatus()`, Postmark's `getHttpStatusCode()`, a wrapped
     * PSR-7 response), falling back to `getCode()` last because most throwables
     * use it for a non-HTTP error code or 0. Only values in the HTTP range are
     * trusted, so a vendor error code that isn't a status is ignored.
     */
    private static function duckTypeStatusCode(\Throwable $e): ?int
    {
        $fromAccessor = self::statusFromAccessors($e);
        if ($fromAccessor !== null) {
            return $fromAccessor;
        }

        if (method_exists($e, 'getResponse')) {
            $response = $e->getResponse();
            if ($response instanceof ResponseInterface) {
                return $response->getStatusCode();
            }
        }

        return self::httpRange($e->getCode());
    }

    /**
     * Try the common direct status accessors, in order of specificity, and
     * return the first that yields a value in the HTTP range.
     */
    private static function statusFromAccessors(\Throwable $e): ?int
    {
        if (method_exists($e, 'getStatusCode')) {
            $status = self::httpRange($e->getStatusCode());
            if ($status !== null) {
                return $status;
            }
        }

        if (method_exists($e, 'getHttpStatus')) {
            $status = self::httpRange($e->getHttpStatus());
            if ($status !== null) {
                return $status;
            }
        }

        if (method_exists($e, 'getHttpStatusCode')) {
            return self::httpRange($e->getHttpStatusCode());
        }

        return null;
    }

    /**
     * Accept an HTTP status only if it's an int in the valid range, so a vendor
     * error code (or a 0) that happens to come back from an accessor is ignored.
     */
    private static function httpRange(mixed $value): ?int
    {
        return is_int($value) && $value >= 100 && $value <= 599 ? $value : null;
    }

    /**
     * Normalize various response types into a consistent [statusCode, body, parsed] tuple.
     *
     * @return array{int|null, string|null, mixed}
     */
    public static function normalize(mixed $response): array
    {
        if ($response instanceof Response) {
            return [
                $response->status(),
                $response->body(),
                $response->json() ?? $response->body(),
            ];
        }

        if ($response instanceof ResponseInterface) {
            $body = (string) $response->getBody();

            try {
                $decoded = json_decode($body, true);
            } catch (JsonException) {
                $decoded = null;
            }

            return [
                $response->getStatusCode(),
                $body,
                $decoded ?? $body,
            ];
        }

        if ($response instanceof JsonResponse) {
            return [
                $response->getStatusCode(),
                $response->getContent() !== false ? $response->getContent() : null,
                $response->getData(true),
            ];
        }

        if (is_array($response)) {
            return [
                null,
                json_encode($response, JSON_THROW_ON_ERROR),
                $response,
            ];
        }

        if (is_object($response)) {
            return self::normalizeObject($response);
        }

        if (is_string($response)) {
            return [null, $response, $response];
        }

        return [null, null, $response];
    }

    /**
     * `stdClass` trees (e.g. from `json_decode($body)` without `assoc=true`) are
     * converted to associative arrays so `Spatie\LaravelData\Data::from()` sees
     * the array shape its `Collection<int, T>` rules expect. Other typed objects
     * are passed through unchanged.
     *
     * @return array{null, string, mixed}
     */
    private static function normalizeObject(object $response): array
    {
        $encoded = json_encode($response, JSON_THROW_ON_ERROR);

        if ($response instanceof \stdClass) {
            return [null, $encoded, json_decode($encoded, true)];
        }

        return [null, $encoded, $response];
    }
}
