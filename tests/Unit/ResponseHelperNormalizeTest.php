<?php

declare(strict_types=1);

namespace Integrations\Tests\Unit;

use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Http\Client\Response as LaravelResponse;
use Illuminate\Http\JsonResponse;
use Integrations\Support\ResponseHelper;
use Integrations\Tests\TestCase;
use stdClass;

class ResponseHelperNormalizeTest extends TestCase
{
    public function test_converts_stdclass_to_associative_array(): void
    {
        $response = (object) ['id' => 1, 'subject' => 'Hello'];

        [$status, $body, $parsed] = ResponseHelper::normalize($response);

        $this->assertNull($status);
        $this->assertSame('{"id":1,"subject":"Hello"}', $body);
        $this->assertSame(['id' => 1, 'subject' => 'Hello'], $parsed);
    }

    public function test_converts_nested_stdclass_recursively(): void
    {
        $response = (object) [
            'tickets' => [
                (object) ['id' => 1, 'subject' => 'First'],
                (object) ['id' => 2, 'subject' => 'Second'],
            ],
            'users' => [
                (object) ['id' => 10, 'name' => 'Alice'],
            ],
            'next_page' => 'https://example.zendesk.com/api/v2/incremental/tickets.json?start_time=123',
            'end_of_stream' => true,
        ];

        [$status, $body, $parsed] = ResponseHelper::normalize($response);

        $this->assertNull($status);
        $this->assertIsString($body);
        $this->assertIsArray($parsed);
        $this->assertIsArray($parsed['tickets']);
        $this->assertSame(['id' => 1, 'subject' => 'First'], $parsed['tickets'][0]);
        $this->assertSame(['id' => 2, 'subject' => 'Second'], $parsed['tickets'][1]);
        $this->assertSame(['id' => 10, 'name' => 'Alice'], $parsed['users'][0]);
        $this->assertTrue($parsed['end_of_stream']);
    }

    public function test_preserves_non_stdclass_typed_object_unchanged(): void
    {
        $response = new class
        {
            public int $id = 99;

            public string $name = 'kept-as-object';
        };

        [$status, $body, $parsed] = ResponseHelper::normalize($response);

        $this->assertNull($status);
        $this->assertSame('{"id":99,"name":"kept-as-object"}', $body);
        $this->assertSame($response, $parsed);
    }

    public function test_extracts_from_laravel_response(): void
    {
        $laravelResponse = new LaravelResponse(new PsrResponse(200, [], '{"ok":true}'));

        [$status, $body, $parsed] = ResponseHelper::normalize($laravelResponse);

        $this->assertSame(200, $status);
        $this->assertSame('{"ok":true}', $body);
        $this->assertSame(['ok' => true], $parsed);
    }

    public function test_extracts_from_psr_response_interface(): void
    {
        $psrResponse = new PsrResponse(201, [], '{"id":42}');

        [$status, $body, $parsed] = ResponseHelper::normalize($psrResponse);

        $this->assertSame(201, $status);
        $this->assertSame('{"id":42}', $body);
        $this->assertSame(['id' => 42], $parsed);
    }

    public function test_falls_back_to_body_when_psr_response_is_not_json(): void
    {
        $psrResponse = new PsrResponse(204, [], 'plain text');

        [$status, $body, $parsed] = ResponseHelper::normalize($psrResponse);

        $this->assertSame(204, $status);
        $this->assertSame('plain text', $body);
        $this->assertSame('plain text', $parsed);
    }

    public function test_extracts_from_symfony_json_response(): void
    {
        $jsonResponse = new JsonResponse(['hello' => 'world'], 202);

        [$status, $body, $parsed] = ResponseHelper::normalize($jsonResponse);

        $this->assertSame(202, $status);
        $this->assertSame('{"hello":"world"}', $body);
        $this->assertSame(['hello' => 'world'], $parsed);
    }

    public function test_passes_array_through(): void
    {
        $array = ['tickets' => [['id' => 1]]];

        [$status, $body, $parsed] = ResponseHelper::normalize($array);

        $this->assertNull($status);
        $this->assertSame('{"tickets":[{"id":1}]}', $body);
        $this->assertSame($array, $parsed);
    }

    public function test_passes_string_through(): void
    {
        [$status, $body, $parsed] = ResponseHelper::normalize('hello');

        $this->assertNull($status);
        $this->assertSame('hello', $body);
        $this->assertSame('hello', $parsed);
    }

    public function test_passes_null_through(): void
    {
        [$status, $body, $parsed] = ResponseHelper::normalize(null);

        $this->assertNull($status);
        $this->assertNull($body);
        $this->assertNull($parsed);
    }

    public function test_empty_stdclass_decodes_to_empty_array(): void
    {
        [, , $parsed] = ResponseHelper::normalize(new stdClass);

        $this->assertSame([], $parsed);
    }
}
