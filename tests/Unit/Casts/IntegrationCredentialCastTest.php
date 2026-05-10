<?php

declare(strict_types=1);

namespace Integrations\Tests\Unit\Casts;

use Integrations\Casts\IntegrationCredentialCast;
use Integrations\Models\Integration;
use Integrations\Tests\Fixtures\TestCredentials;
use Integrations\Tests\TestCase;
use InvalidArgumentException;
use stdClass;

class IntegrationCredentialCastTest extends TestCase
{
    private IntegrationCredentialCast $cast;

    private Integration $model;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cast = new IntegrationCredentialCast;
        $this->model = new Integration;
    }

    public function test_set_null_returns_null(): void
    {
        $this->assertNull($this->cast->set($this->model, 'credentials', null, []));
    }

    public function test_set_array_round_trips_through_get(): void
    {
        $encrypted = $this->cast->set($this->model, 'credentials', ['api_key' => 'secret'], []);

        $this->assertIsString($encrypted);

        $decoded = $this->cast->get($this->model, 'credentials', $encrypted, []);

        $this->assertSame(['api_key' => 'secret'], $decoded);
    }

    public function test_set_data_instance_round_trips_through_get_as_array(): void
    {
        $encrypted = $this->cast->set($this->model, 'credentials', new TestCredentials('secret'), []);

        $this->assertIsString($encrypted);

        $decoded = $this->cast->get($this->model, 'credentials', $encrypted, []);

        $this->assertSame(['api_key' => 'secret'], $decoded);
    }

    public function test_set_string_throws_invalid_argument(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/got string/');

        $this->cast->set($this->model, 'credentials', 'pre-encrypted-blob', []);
    }

    public function test_set_integer_throws_invalid_argument(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/got int/');

        $this->cast->set($this->model, 'credentials', 42, []);
    }

    public function test_set_bool_throws_invalid_argument(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/got bool/');

        $this->cast->set($this->model, 'credentials', true, []);
    }

    public function test_set_non_data_object_throws_invalid_argument(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/got stdClass/');

        $this->cast->set($this->model, 'credentials', new stdClass, []);
    }
}
