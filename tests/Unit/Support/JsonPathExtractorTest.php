<?php

declare(strict_types=1);

namespace Integrations\Tests\Unit\Support;

use Integrations\Support\JsonPathExtractor;
use Integrations\Tests\TestCase;
use InvalidArgumentException;

class JsonPathExtractorTest extends TestCase
{
    public function test_defaults_to_the_active_connection_driver(): void
    {
        // The test connection is sqlite.
        $this->assertSame(
            "json_extract(error, '$.message')",
            JsonPathExtractor::stringPath('error', 'message'),
        );
    }

    public function test_builds_the_sqlite_expression(): void
    {
        $this->assertSame(
            "json_extract(error, '$.message')",
            JsonPathExtractor::stringPath('error', 'message', 'sqlite'),
        );
    }

    public function test_builds_the_pgsql_expression(): void
    {
        $this->assertSame("error->>'message'", JsonPathExtractor::stringPath('error', 'message', 'pgsql'));
    }

    public function test_builds_the_mysql_expression(): void
    {
        $this->assertSame(
            "JSON_UNQUOTE(JSON_EXTRACT(error, '$.message'))",
            JsonPathExtractor::stringPath('error', 'message', 'mysql'),
        );
    }

    public function test_rejects_an_unsafe_column(): void
    {
        $this->expectException(InvalidArgumentException::class);

        JsonPathExtractor::stringPath('error; DROP TABLE x', 'message');
    }

    public function test_rejects_an_unsafe_key(): void
    {
        $this->expectException(InvalidArgumentException::class);

        JsonPathExtractor::stringPath('error', "message'); --");
    }
}
