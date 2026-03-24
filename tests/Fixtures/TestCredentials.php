<?php

declare(strict_types=1);

namespace Integrations\Tests\Fixtures;

use Spatie\LaravelData\Data;

class TestCredentials extends Data
{
    public function __construct(
        public string $api_key,
    ) {}
}
