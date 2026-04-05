<?php

declare(strict_types=1);

namespace Integrations\Support;

use GuzzleHttp\Exception\RequestException as GuzzleRequestException;
use Illuminate\Http\Client\RequestException as LaravelRequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Http\JsonResponse;
use JsonException;
use Psr\Http\Message\ResponseInterface;

use function Safe\json_decode;
use function Safe\json_encode;

final class ResponseHelper
{
    /**
     * Extract the HTTP status code from an exception, if available.
     */
    public static function extractStatusCode(\Throwable $e): ?int
    {
        if ($e instanceof LaravelRequestException) {
            return $e->response->status();
        }

        if ($e instanceof GuzzleRequestException && $e->getResponse() !== null) {
            return $e->getResponse()->getStatusCode();
        }

        return null;
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
            $encoded = json_encode($response, JSON_THROW_ON_ERROR);

            return [null, $encoded, $response];
        }

        if (is_string($response)) {
            return [null, $response, $response];
        }

        return [null, null, $response];
    }
}
