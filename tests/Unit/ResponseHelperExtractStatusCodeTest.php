<?php

declare(strict_types=1);

namespace Integrations\Tests\Unit;

use GuzzleHttp\Exception\RequestException as GuzzleRequestException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Illuminate\Http\Client\RequestException as LaravelRequestException;
use Illuminate\Http\Client\Response as LaravelResponse;
use Integrations\Support\ResponseHelper;
use Integrations\Tests\TestCase;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;

class ResponseHelperExtractStatusCodeTest extends TestCase
{
    public function test_extracts_from_laravel_request_exception(): void
    {
        $psrResponse = new Response(422);
        $laravelResponse = new LaravelResponse($psrResponse);
        $e = new LaravelRequestException($laravelResponse);

        $this->assertSame(422, ResponseHelper::extractStatusCode($e));
    }

    public function test_extracts_from_guzzle_request_exception(): void
    {
        $request = new Request('GET', 'https://example.com');
        $response = new Response(503);
        $e = new GuzzleRequestException('Server error', $request, $response);

        $this->assertSame(503, ResponseHelper::extractStatusCode($e));
    }

    public function test_extracts_from_symfony_http_exception(): void
    {
        $e = new HttpException(429, 'Too Many Requests');

        $this->assertSame(429, ResponseHelper::extractStatusCode($e));
    }

    public function test_extracts_from_wrapped_guzzle_exception(): void
    {
        $request = new Request('GET', 'https://example.com');
        $response = new Response(429);
        $guzzle = new GuzzleRequestException('Rate limited', $request, $response);
        $wrapper = new RuntimeException('SDK error', 0, $guzzle);

        $this->assertSame(429, ResponseHelper::extractStatusCode($wrapper));
    }

    public function test_extracts_from_deeply_wrapped_guzzle_exception(): void
    {
        $request = new Request('GET', 'https://example.com');
        $response = new Response(502);
        $guzzle = new GuzzleRequestException('Bad gateway', $request, $response);
        $inner = new RuntimeException('Inner', 0, $guzzle);
        $outer = new RuntimeException('Outer', 0, $inner);

        $this->assertSame(502, ResponseHelper::extractStatusCode($outer));
    }

    public function test_extracts_from_wrapped_laravel_exception(): void
    {
        $psrResponse = new Response(500);
        $laravelResponse = new LaravelResponse($psrResponse);
        $laravel = new LaravelRequestException($laravelResponse);
        $wrapper = new RuntimeException('SDK error', 0, $laravel);

        $this->assertSame(500, ResponseHelper::extractStatusCode($wrapper));
    }

    public function test_extracts_from_wrapped_symfony_http_exception(): void
    {
        $symfony = new HttpException(403, 'Forbidden');
        $wrapper = new RuntimeException('SDK error', 0, $symfony);

        $this->assertSame(403, ResponseHelper::extractStatusCode($wrapper));
    }

    public function test_returns_null_for_unknown_exception(): void
    {
        $e = new RuntimeException('Unknown error');

        $this->assertNull(ResponseHelper::extractStatusCode($e));
    }

    public function test_returns_null_for_guzzle_without_response(): void
    {
        $request = new Request('GET', 'https://example.com');
        $e = new GuzzleRequestException('Connection failed', $request);

        $this->assertNull(ResponseHelper::extractStatusCode($e));
    }

    public function test_prefers_outermost_recognized_exception(): void
    {
        $request = new Request('GET', 'https://example.com');
        $guzzle = new GuzzleRequestException('Inner', $request, new Response(500));
        $symfony = new HttpException(429, 'Outer', $guzzle);

        $this->assertSame(429, ResponseHelper::extractStatusCode($symfony));
    }
}
