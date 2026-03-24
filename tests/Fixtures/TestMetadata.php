<?php

declare(strict_types=1);

namespace Integrations\Tests\Fixtures;

use Spatie\LaravelData\Data;

class TestMetadata extends Data
{
    public function __construct(
        public string $region,
    ) {}
}
