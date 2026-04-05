<?php

declare(strict_types=1);

namespace Integrations\Tests\Fixtures;

use Spatie\LaravelData\Data;

class TestTokenResponse extends Data
{
    public function __construct(
        public string $token,
        public string $user,
    ) {}
}
